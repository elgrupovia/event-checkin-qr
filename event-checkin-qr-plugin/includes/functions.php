<?php
/**
 * functions.php — Plugin Event Check-In QR
 * Genera un PDF con código QR personalizado al ejecutar el hook JetFormBuilder "inscripciones_qr"
 * * ✅ Implementada lógica de búsqueda de eventos más robusta (exacta + similitud)
 */

if (!defined('ABSPATH')) {
    exit; // Evita acceso directo
}

// Asegúrate de que los archivos de librerías se carguen correctamente
// Cambia la ruta si tu estructura es diferente.
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

        error_log("🔍 Iniciando búsqueda del evento: '{$titulo_evento_formulario}'");

        $post_id = null;
        $titulo_evento_encontrado = $titulo_evento_formulario; 

        if ($titulo_evento_formulario) {
            
            // 🚀 LÓGICA DE BÚSQUEDA ROBUSTA (Triple Intento)
            
            // 1. Búsqueda Exacta y Exhaustiva (Iterando posts)
            error_log("🔎 Intento 1: Búsqueda Exacta Exhaustiva...");
            
            $todos_los_eventos = get_posts([
                'post_type'      => 'eventos_2025',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);
            
            foreach ($todos_los_eventos as $id_evento) {
                $titulo_post = trim(get_the_title($id_evento));
                // strcasecmp = comparación de cadenas sin distinción de mayúsculas/minúsculas
                if (strcasecmp($titulo_post, $titulo_evento_formulario) === 0) {
                    $post_id = $id_evento;
                    break;
                }
            }

            // 2. Búsqueda por Similitud (Fuzzy Search) si el Intento 1 falla
            if (!$post_id) {
                error_log("❌ Intento 1 falló. 🔎 Intento 2: Búsqueda por Similitud...");

                $eventos_similares = get_posts([
                    'post_type'      => 'eventos_2025',
                    'post_status'    => 'publish',
                    'posts_per_page' => 1, 
                    'fields'         => 'ids',
                    's'              => $titulo_evento_formulario, // Búsqueda nativa de WP (más flexible)
                    'orderby'        => 'relevance',
                ]);
                
                if (!empty($eventos_similares)) {
                    $post_id = $eventos_similares[0];
                }
            }

            // ▪ Resultado Final de la Búsqueda
            if ($post_id) {
                // Si encontramos un ID, obtenemos el título real de la publicación
                $titulo_evento_encontrado = trim(get_the_title($post_id));
                error_log("✅ EVENTO FINAL ENCONTRADO: ID={$post_id}, Título='{$titulo_evento_encontrado}'");
            } else {
                error_log("❌ La búsqueda fue infructuosa. La imagen NO se insertará.");
            }
            
        } else {
            error_log("⚠️ No se recibió el nombre del evento en el formulario (campo eventos_2025)");
        }
        
        // El título a mostrar en el PDF
        $titulo_a_mostrar = $titulo_evento_encontrado ?: 'Evento no identificado';


        // --- LÓGICA DE GENERACIÓN DE PDF Y QR ---

        // ▪ Generar QR
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_persona}\nCargo: {$cargo_persona}";
        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->build();

        $upload_dir = wp_upload_dir();
        // Usamos un nombre único para el QR temporal
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png'; 
        $qr->saveToFile($qr_path);
        error_log("🧾 QR generado en: " . $qr_path);

        // ▪ Crear PDF
        $pdf = new TCPDF();
        $pdf->AddPage();
        
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // ▪ Imagen del evento (si se encontró)
        $imagen_insertada = false;
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                
                // Intenta obtener la ruta local
                $imagen_path = '';
                $imagen_id = get_post_thumbnail_id($post_id);
                $imagen_meta = wp_get_attachment_metadata($imagen_id);
                if ($imagen_meta) {
                   $imagen_path = $upload_dir['basedir'] . '/' . $imagen_meta['file'];
                }
                
                $tmp = null; // Variable para la ruta temporal si se descarga

                if (!file_exists($imagen_path)) {
                    // Si no existe localmente, intentar descargarla
                    if (function_exists('download_url')) {
                        $tmp = download_url($imagen_url);
                        if (!is_wp_error($tmp)) {
                            $imagen_path = $tmp;
                        }
                    } else {
                        error_log("⚠️ Función 'download_url' no disponible. No se puede intentar descargar la imagen.");
                    }
                }

                if (file_exists($imagen_path)) {
                    try {
                        // Insertar imagen: x, y, ancho, alto
                        $pdf->Image($imagen_path, 15, 20, 180, 60); 
                        $imagen_insertada = true;
                        error_log("✅ Imagen destacada insertada correctamente");
                    } catch (Exception $e) {
                        error_log("❌ Error al insertar imagen en PDF (TCPDF): " . $e->getMessage());
                    }
                } else {
                    error_log("⚠️ La imagen destacada no se pudo localizar físicamente");
                }
                
                // Limpiar archivo temporal si se descargó
                if ($tmp && !is_wp_error($tmp) && file_exists($tmp)) {
                    @unlink($tmp);
                    error_log("🗑️ Archivo temporal de imagen descargada limpiado.");
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
        // Coordenadas QR: centrado (70mm)
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
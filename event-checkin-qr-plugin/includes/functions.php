<?php
/**
 * functions.php — Plugin Event Check-In QR
 * Genera un PDF con código QR personalizado
 * ✅ VERSIÓN SIMPLIFICADA: Solo búsqueda EXACTA (sin fuzzy matching)
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

/**
 * Normaliza texto para comparación exacta
 */
function normalizar_texto_exacto($texto) {
    // Decodificar entidades HTML
    $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Convertir a minúsculas
    $texto = mb_strtolower($texto, 'UTF-8');
    
    // Normalizar espacios
    $texto = preg_replace('/\s+/', ' ', trim($texto));
    
    // Reemplazar caracteres especiales comunes
    $texto = str_replace(['&amp;', '&'], 'y', $texto);
    $texto = str_replace(['–', '—', '-'], ' ', $texto);
    
    // Quitar puntuación al final
    $texto = rtrim($texto, '.,;:!?');
    
    return $texto;
}

/**
 * Busca el evento SOLO por coincidencia EXACTA
 */
function buscar_evento_exacto($titulo_buscado) {
    error_log("🔍 === BÚSQUEDA EXACTA DE EVENTO ===");
    error_log("📝 Título buscado: '{$titulo_buscado}'");
    
    $titulo_normalizado = normalizar_texto_exacto($titulo_buscado);
    error_log("🔤 Normalizado: '{$titulo_normalizado}'");
    
    // Obtener todos los eventos
    $eventos = get_posts([
        'post_type'      => 'eventos',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC'
    ]);
    
    if (empty($eventos)) {
        error_log("❌ No se encontraron eventos publicados");
        return null;
    }
    
    error_log("📊 Total eventos disponibles: " . count($eventos));
    error_log("🔎 Buscando coincidencia EXACTA...");
    
    $candidatos = [];
    
    foreach ($eventos as $evento) {
        $titulo_evento = get_the_title($evento->ID);
        $titulo_evento_normalizado = normalizar_texto_exacto($titulo_evento);
        
        // SOLO coincidencia EXACTA
        if ($titulo_normalizado === $titulo_evento_normalizado) {
            error_log("✅ ¡COINCIDENCIA EXACTA ENCONTRADA!");
            error_log("   ID: {$evento->ID}");
            error_log("   Título original: '{$titulo_evento}'");
            return $evento->ID;
        }
        
        // Guardar candidatos para debugging (solo primeros 5)
        if (count($candidatos) < 5 && 
            (strpos($titulo_evento_normalizado, 'arquitectura') !== false || 
             strpos($titulo_evento_normalizado, 'barcelona') !== false)) {
            $candidatos[] = [
                'id' => $evento->ID,
                'titulo' => $titulo_evento,
                'normalizado' => $titulo_evento_normalizado
            ];
        }
    }
    
    // Si no hay coincidencia exacta, mostrar candidatos para debug
    error_log("❌ No se encontró coincidencia exacta");
    
    if (!empty($candidatos)) {
        error_log("📋 Eventos similares encontrados (para debugging):");
        foreach ($candidatos as $candidato) {
            error_log("   • ID: {$candidato['id']}");
            error_log("     Original: '{$candidato['titulo']}'");
            error_log("     Normalizado: '{$candidato['normalizado']}'");
            
            // Comparar carácter por carácter
            $diff = strcmp($titulo_normalizado, $candidato['normalizado']);
            if ($diff !== 0) {
                error_log("     ⚠️ Diferencia detectada. Comparando:");
                error_log("       Buscado:    '{$titulo_normalizado}'");
                error_log("       Candidato:  '{$candidato['normalizado']}'");
                
                // Mostrar longitudes
                error_log("       Longitud buscado: " . strlen($titulo_normalizado));
                error_log("       Longitud candidato: " . strlen($candidato['normalizado']));
            }
        }
    }
    
    error_log("🔍 === FIN BÚSQUEDA ===");
    return null;
}

/**
 * Función principal: genera el PDF con QR + imagen del evento
 */
function generar_qr_pdf_personalizado($request, $action_handler) {
    error_log("🚀 [inscripciones_qr] Hook ejecutado - " . date('Y-m-d H:i:s'));
    error_log("📥 Datos del formulario: " . print_r($request, true));

    try {
        // Datos del participante
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre']) ? sanitize_text_field($request['nombre']) : 'Invitado';
        $cargo_persona  = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo no especificado';

        error_log("👤 Participante: {$nombre_persona} | {$cargo_persona} | {$nombre_empresa}");

        // Obtener nombre del evento
        $titulo_evento_formulario = '';
        if (isset($request['eventos_2025']) && !empty($request['eventos_2025'][0])) {
            $titulo_evento_formulario = trim($request['eventos_2025'][0]);
        }

        $post_id = null;
        $titulo_evento_encontrado = $titulo_evento_formulario;

        if ($titulo_evento_formulario) {
            // 🎯 BÚSQUEDA SOLO EXACTA
            $post_id = buscar_evento_exacto($titulo_evento_formulario);
            
            if ($post_id) {
                $titulo_evento_encontrado = get_the_title($post_id);
                error_log("✅ EVENTO ENCONTRADO: ID={$post_id}");
                error_log("✅ Título: '{$titulo_evento_encontrado}'");
            } else {
                error_log("❌ NO SE ENCONTRÓ EL EVENTO");
                error_log("⚠️ SOLUCIÓN: Verifica que el título del evento en WordPress sea EXACTAMENTE:");
                error_log("   '{$titulo_evento_formulario}'");
            }
        } else {
            error_log("⚠️ Campo eventos_2025 vacío");
        }
        
        $titulo_a_mostrar = $titulo_evento_encontrado ?: 'Evento no identificado';

        // --- GENERACIÓN DE QR ---
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_persona}\nCargo: {$cargo_persona}";
        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->build();

        $upload_dir = wp_upload_dir();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);
        error_log("🧾 QR generado: " . basename($qr_path));

        // --- CREAR PDF ---
        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Imagen del evento
        $imagen_insertada = false;
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_path = '';
                $imagen_id = get_post_thumbnail_id($post_id);
                $imagen_meta = wp_get_attachment_metadata($imagen_id);
                
                if ($imagen_meta && isset($imagen_meta['file'])) {
                   $imagen_path = $upload_dir['basedir'] . '/' . $imagen_meta['file'];
                }
                
                $tmp = null;

                if (!file_exists($imagen_path) && function_exists('download_url')) {
                    $tmp = download_url($imagen_url);
                    if (!is_wp_error($tmp)) {
                        $imagen_path = $tmp;
                    }
                }

                if (file_exists($imagen_path)) {
                    try {
                        $pdf->Image($imagen_path, 15, 20, 180, 60);
                        $imagen_insertada = true;
                        error_log("✅ Imagen insertada en PDF");
                    } catch (Exception $e) {
                        error_log("❌ Error al insertar imagen: " . $e->getMessage());
                    }
                }
                
                if ($tmp && !is_wp_error($tmp) && file_exists($tmp)) {
                    @unlink($tmp);
                }
            } else {
                error_log("⚠️ Evento sin imagen destacada");
            }
        }
        
        // Contenido del PDF
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
        $pdf->Image($qr_path, 70, $pdf->GetY(), 70, 70, 'PNG');

        // Guardar PDF
        $pdf_filename = 'entrada_' . sanitize_file_name($nombre_persona) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        error_log("✅ PDF generado: {$pdf_filename}");

        @unlink($qr_path);

    } catch (Exception $e) {
        error_log("❌ ERROR CRÍTICO: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }
}

add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

error_log("✅ Plugin QR cargado - Versión: Búsqueda Exacta - " . date('Y-m-d H:i:s'));
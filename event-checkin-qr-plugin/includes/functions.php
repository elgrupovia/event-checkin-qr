<?php
/**
 * functions.php — Plugin Event Check-In QR
 * Genera un PDF con código QR personalizado al ejecutar el hook JetFormBuilder "inscripciones_qr"
 * ✅ Búsqueda mejorada con normalización de texto y múltiples estrategias
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

/**
 * Normaliza texto para comparación (quita acentos, convierte a minúsculas, normaliza espacios)
 */
function normalizar_texto($texto) {
    // Convertir a minúsculas
    $texto = mb_strtolower($texto, 'UTF-8');
    
    // Quitar acentos y caracteres especiales
    $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    
    // Normalizar espacios múltiples y trim
    $texto = preg_replace('/\s+/', ' ', trim($texto));
    
    // Quitar caracteres especiales excepto espacios y guiones
    $texto = preg_replace('/[^a-z0-9\s\-]/', '', $texto);
    
    return $texto;
}

function primeras_palabras($texto, $limite = 3) {
    // Elimina espacios múltiples y espacios al inicio/final
    $texto = trim(preg_replace('/\s+/', ' ', $texto));

    // Divide en palabras por espacio
    $palabras = explode(' ', $texto);

    // Coge solo las primeras $limite palabras
    $primeras = array_slice($palabras, 0, $limite);

    // Devuelve como texto unido
    return implode(' ', $primeras);
}

/**
 * Busca el evento usando múltiples estrategias
 */
function buscar_evento_robusto($titulo_buscado) {
     error_log((string)"🔍 === INICIO BÚSQUEDA ROBUSTA DE EVENTO ===" . $titulo_buscado);
    //error_log((string)"📝 Título recibido del formulario: '{$titulo_buscado}'");
    
    
    //$titulo_normalizado = normalizar_texto($titulo_buscado);
    $primeras = primeras_palabras($titulo_buscado, 3);
    //error_log((string)"🔤 Título normalizado: '{$titulo_normalizado}'");
    $ciudades = ['barcelona', 'valencia' , 'madrid', 'bilbao'];

$ciudad_form = null;
$normForm = normalizar_texto($titulo_buscado); // tu función

foreach ($ciudades as $c) {
    if (stripos($normForm, normalizar_texto($c)) !== false) {
        $ciudad_form = $c; // ciudad detectada
        break;
    }
}


    
    // Obtener TODOS los eventos publicados, filtrando por año y ciudad si se requiere
    $args = [
        'post_type'      => 'eventos',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        's'              => $primeras,
        'tax_query' => [
            'relation' => 'AND',
            [
                'taxonomy' => 'ano',
                'field'    => 'slug',
                'terms'    => ['2025'],
            ],
            [
                'taxonomy' => 'ciudades',
                'field'    => 'slug',
                'terms'    => $ciudades, 
            ],
        ],
    ];

    

    
    $eventos = get_posts($args);
    error_log('EVENTOS encontrados: ' . count($eventos));
   $event_id = 0;

// 1) Comprobamos que tenemos eventos y que detectamos una ciudad en el título del formulario
if (!empty($eventos) && !empty($ciudad_form)) {

    // 2) Preparamos la ciudad en "formato normalizado" para comparar mejor (sin acentos, minúsculas, etc.)
    $ciudad_buscar = normalizar_texto($ciudad_form);

    // 3) Recorremos cada evento devuelto por la query
    foreach ($eventos as $evento) {

        // 4) Obtenemos el título del evento y lo normalizamos igual
        $titulo_evento = get_the_title($evento->ID);
        $titulo_evento_norm = normalizar_texto($titulo_evento);

        // 5) Si el título del evento contiene la ciudad, ¡es nuestro candidato!
        if (stripos($titulo_evento_norm, $ciudad_buscar) !== false) {
            $event_id = (int) $evento->ID; // guardamos el ID
            break; // paramos al encontrar el primero que coincide
        }
    }
}
error_log('🏙️ Ciudad detectada: ' . ($ciudad_form ?: 'ninguna'));
error_log('✅ Evento elegido ID: ' . $event_id);
    return $event_id;
}
   
/**
 * Función principal: genera el PDF con QR + imagen del evento
 */
function generar_qr_pdf_personalizado($request, $action_handler) {
   // error_log((string)"🚀 [inscripciones_qr] Hook ejecutado");
    //error_log((string)("📥 Datos completos del formulario: " . print_r($request, true)));

    try {
        // Datos del participante
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre']) ? sanitize_text_field($request['nombre']) : 'Invitado';
        $cargo_persona  = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo no especificado';

   // error_log((string)"📦 Datos recibidos: Empresa={$nombre_empresa}, Nombre={$nombre_persona}, Cargo={$cargo_persona}");

        // Obtener nombre del evento desde el formulario
       $titulo_evento_formulario = '';
        if (isset($request['eventos_2025']) && !empty($request['eventos_2025'][0])) {
            $titulo_evento_formulario = trim(sanitize_text_field($request['eventos_2025'][0]));
        }

        $post_id = null;
        $titulo_evento_encontrado = $titulo_evento_formulario;

        if ($titulo_evento_formulario) {
            // 🚀 BÚSQUEDA ROBUSTA CON DEPURACIÓN COMPLETA
            $post_id = buscar_evento_robusto($titulo_evento_formulario);
            
            if ($post_id) {
                $titulo_evento_encontrado = trim(get_the_title($post_id));
                error_log((string)"✅ EVENTO FINAL ENCONTRADO: ID={$post_id}, Título='{$titulo_evento_encontrado}'");
            } else {
                error_log((string)"❌ No se pudo encontrar el evento. La imagen NO se insertará.");
            }
        } else {
            error_log((string)"⚠️ No se recibió el nombre del evento en el formulario (campo eventos_2025)");
        }
        
        $titulo_a_mostrar = $titulo_evento_encontrado ?: 'Evento no identificado';

        // --- GENERACIÓN DE PDF Y QR (sin cambios) ---
        
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
    error_log((string)("🧾 QR generado en: " . $qr_path));

        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Imagen del evento (si se encontró)
        $imagen_insertada = false;
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_path = '';
                $imagen_id = get_post_thumbnail_id($post_id);
                $imagen_meta = wp_get_attachment_metadata($imagen_id);
                if ($imagen_meta) {
                   $imagen_path = $upload_dir['basedir'] . '/' . $imagen_meta['file'];
                }
                
                $tmp = null;

                if (!file_exists($imagen_path)) {
                    if (function_exists('download_url')) {
                        $tmp = download_url($imagen_url);
                        if (!is_wp_error($tmp)) {
                            $imagen_path = $tmp;
                        }
                    }
                }

                if (file_exists($imagen_path)) {
                    try {
                        $pdf->Image($imagen_path, 15, 20, 180, 60);
                        $imagen_insertada = true;
                        error_log("✅ Imagen destacada insertada correctamente");
                    } catch (Exception $e) {
                        error_log("❌ Error al insertar imagen en PDF: " . $e->getMessage());
                    }
                } else {
                    error_log("⚠️ La imagen destacada no se pudo localizar físicamente");
                }
                
                if ($tmp && !is_wp_error($tmp) && file_exists($tmp)) {
                    @unlink($tmp);
                }
            } else {
                error_log("⚠️ El evento ID={$post_id} no tiene imagen destacada");
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
        error_log("✅ PDF generado correctamente en: " . $pdf_path);

        @unlink($qr_path);

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
    }
}
?>
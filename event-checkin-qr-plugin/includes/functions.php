<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho - Multi-Evento)
 * Description: Genera PDF con QR para una lista específica de eventos con calendario superpuesto y badge optimizado.
 * Version: 3.5.0
 */

if (!defined('ABSPATH')) exit;

// Carga de dependencias de Composer (Endroid QR y TCPDF)
require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Función auxiliar para manejar imágenes y rutas locales
 */
function optimizar_imagen_para_pdf($imagen_url, $upload_dir){
    $tmp = null;
    $imagen_path = '';
    $attachment_id = attachment_url_to_postid($imagen_url);

    if ($attachment_id) {
        $meta = wp_get_attachment_metadata($attachment_id);
        if ($meta && isset($meta['file'])) {
            $imagen_path = $upload_dir['basedir'].'/'.$meta['file'];
        }
    }

    if (!file_exists($imagen_path) && function_exists('download_url')) {
        $tmp = download_url($imagen_url, 300);
        if (!is_wp_error($tmp)) {
            $imagen_path = $tmp;
        }
    }

    return ['path' => $imagen_path, 'tmp' => $tmp];
}

// Hook para JetFormBuilder
add_action(
    'jet-form-builder/custom-action/inscripciones_qr',
    'generar_qr_pdf_personalizado',
    10,
    3
);

function generar_qr_pdf_personalizado($request, $action_handler) {

    try {
        // --- LISTA DE IDS DE EVENTOS PERMITIDOS ---
        $eventos_permitidos = [
            50339, 50342, 50352, 50364, 50355, 50379, 50383, 52217, 51321, 50391,
            50414, 50420, 54395, 50432, 50435, 50438, 50442, 50445, 50451, 50466,
            50469, 51324, 50490, 50520, 50530, 50533, 50605, 51033, 50493, 51448,
            51037, 51237, 51269, 51272, 51282, 54163, 51301, 51285, 51291, 51294,
            51297
        ];

        // Detectar el ID del evento actual desde el formulario
        $post_id = isset($request['refer_post_id']) ? intval($request['refer_post_id']) : get_the_ID();

        // Validar si el ID es parte de la lista; si no, por defecto usamos el principal
        if (!in_array($post_id, $eventos_permitidos)) {
            $post_id = 50339; 
        }

        // --- DATOS DEL ASISTENTE ---
        $nombre_empresa = sanitize_text_field($request['nombre_de_empresa'] ?? 'Empresa Desconocida');
        $nombre = sanitize_text_field($request['nombre'] ?? 'Invitado');
        $apellidos = sanitize_text_field($request['apellidos'] ?? '');

        // --- BÚSQUEDA DIFUSA DE EVENTO (eventos_2025) ---
        $raw_evento = $request['eventos_2025'] ?? '';
        
        // Si es array, tomamos el primer valor para comparar
        if (is_array($raw_evento)) {
            $raw_evento = reset($raw_evento); 
        }
        $evento_formulario = sanitize_text_field($raw_evento);

        if (!empty($evento_formulario)) {
            $mejor_id = 0;
            $max_similitud = 0;

            foreach ($eventos_permitidos as $eid) {
                $titulo = get_the_title($eid);
                similar_text(mb_strtolower($evento_formulario), mb_strtolower($titulo), $porc);
                
                if ($porc > $max_similitud) {
                    $max_similitud = $porc;
                    $mejor_id = $eid;
                }
            }

            // Asignar el mejor candidato
            if ($mejor_id > 0) {
                $post_id = $mejor_id;
                error_log("🎯 Evento coincidente: '$evento_formulario' -> ID: $post_id ($max_similitud%)");
            }
        }

        $nombre_completo = html_entity_decode(trim("$nombre $apellidos"), ENT_QUOTES, 'UTF-8');

        // --- DATOS DINÁMICOS DEL EVENTO ---
        $titulo_evento = get_the_title($post_id);
        $ubicacion_raw = get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Ubicación no disponible';
        $ubicacion = html_entity_decode($ubicacion_raw, ENT_QUOTES, 'UTF-8');

        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        $ts = is_numeric($fecha_raw) ? $fecha_raw : strtotime($fecha_raw);

        $dia  = date('d', $ts);
        $mes  = strtoupper(date_i18n('M', $ts));
        $fecha_formateada = date('d/m/Y H:i', $ts);

        $upload_dir = wp_upload_dir();

        /**
         * GENERACIÓN DE QR
         */
        $qr_url = home_url('/checkin/?') . http_build_query([
            'empresa' => $nombre_empresa,
            'nombre'  => $nombre_completo,
            'evento'  => $titulo_evento,
            'ev_id'   => $post_id // ID del evento para facilitar el check-in
        ]);

        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($qr_url)
            ->size(300)
            ->margin(10)
            ->build();

        $qr_path = $upload_dir['basedir'].'/qr_'.uniqid().'.png';
        $qr->saveToFile($qr_path);

        /**
         * GENERACIÓN DE PDF (TCPDF)
         */
        $pdf = new TCPDF('P','mm','A4',true,'UTF-8',false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8,8,8);
        $pdf->SetAutoPageBreak(false,0);
        $pdf->AddPage();

        // Fondo del Ticket
        $pdf->SetFillColor(245,245,247);
        $pdf->RoundedRect(8,8,194,279,6,'1111','F');

        /**
         * IMAGEN DE CABECERA (Dinámica del Evento)
         */
        $y_actual = 8;
        $img_url = get_the_post_thumbnail_url($post_id, 'full');

        if ($img_url) {
            $img = optimizar_imagen_para_pdf($img_url, $upload_dir);
            if (file_exists($img['path'])) {
                list($w,$h) = getimagesize($img['path']);
                $alto = min(($h * 194) / $w, 100);

                $pdf->StartTransform();
                $pdf->RoundedRect(8,8,194,$alto,6,'1111','CNZ');
                $pdf->Image($img['path'],8,8,194,$alto);
                $pdf->StopTransform();

                $y_actual = 8 + $alto;
            }
        }

        /**
         * ICONO CALENDARIO SUPERPUESTO
         */
        $cal_x = 14; 
        $cal_y = 14; 
        $cal_w = 22;
        $cal_h = 22;

        $pdf->SetFillColor(220, 220, 220); // Sombra
        $pdf->RoundedRect($cal_x + 0.4, $cal_y + 0.4, $cal_w, $cal_h, 2.2, '1111', 'F');
        $pdf->SetFillColor(255, 255, 255); // Fondo
        $pdf->RoundedRect($cal_x, $cal_y, $cal_w, $cal_h, 2.2, '1111', 'F');

        $pdf->SetTextColor(35, 35, 35);
        $pdf->SetFont('gothamb', '', 24);
        $pdf->SetXY($cal_x, $cal_y + 2);
        $pdf->Cell($cal_w, 11, $dia, 0, 0, 'C');

        // MES: más relleno y gris
        $pdf->SetFont('gothambook', '', 11); // Gotham Book (peso 400)
        $pdf->SetTextColor(80, 80, 80); // Gris sólido

        $mes_texto = ucfirst(strtolower($mes));
        $pdf->SetXY($cal_x, $cal_y + 13);

        // Dibuja el texto varias veces para simular grosor
        $offsets = [
            [0, 0],
            [0.2, 0],
            [-0.2, 0],
            [0, 0.2],
            [0, -0.2],
        ];

        foreach ($offsets as $o) {
            $pdf->SetXY($cal_x + $o[0], $cal_y + 13 + $o[1]);
            $pdf->Cell($cal_w, 7, $mes_texto, 0, 0, 'C');
        }

        /**
         * BADGE "ENTRADA CONFIRMADA" (Versión Compacta)
         */
        $y_actual += 8;
        $badge_w = 80; // Antes era 145, ahora es más estrecho
        $badge_x = (210 - $badge_w) / 2; // Recalcular centro
        $badge_h = 11;

        $pdf->SetFillColor(76, 175, 80); // Color verde
        $pdf->RoundedRect($badge_x, $y_actual, $badge_w, $badge_h, 3, '1111', 'F');

        $pdf->SetTextColor(255, 255, 255);
        $texto_confirmacion = 'ENTRADA CONFIRMADA';
        $pdf->SetFont('helvetica', 'B', 11); // Bajamos un punto el tamaño para que quepa bien
        
        $ancho_texto_real = $pdf->GetStringWidth($texto_confirmacion);
        $tick_w = 6;
        $space = 1;
        $total_contenido_w = $tick_w + $space + $ancho_texto_real;
        
        // El inicio del texto debe ser relativo al centro del badge
        $start_x = $badge_x + ($badge_w - $total_contenido_w) / 2;

        $pdf->SetFont('zapfdingbats', '', 11);
        $pdf->SetXY($start_x, $y_actual + 2.5);
        $pdf->Cell($tick_w, 6, '3', 0, 0, 'C'); 

        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetXY($start_x + $tick_w + $space, $y_actual + 2.5);
        $pdf->Cell($ancho_texto_real, 6, $texto_confirmacion, 0, 0, 'L');

        /**
         * DETALLES: FECHA Y UBICACIÓN
         */
        $y_actual += 16;
        $pdf->SetFont('helvetica','',13); 
        $pdf->SetTextColor(50,50,50);    
        $pdf->SetXY(15, $y_actual);
        $pdf->MultiCell(180,6,'FECHA: '.$fecha_formateada.' | LUGAR: '.$ubicacion,0,'C'); 

        /**
         * NOMBRE DEL ASISTENTE
         */
        $pdf->Ln(6);
        $pdf->SetFont('helvetica','B',24);
        $pdf->SetTextColor(60,60,65);
        $pdf->Cell(0,12,$nombre_completo,0,1,'C');

        $pdf->SetFont('helvetica','B',14);
        $pdf->SetTextColor(100,100,105);
        $pdf->Cell(0,8,mb_strtoupper($nombre_empresa,'UTF-8'),0,1,'C');

        /**
         * RENDERIZADO QR 
         */
        $pdf->Ln(2); 
        $y_qr = $pdf->GetY(); 
        
        $qr_size = 115; 
        $qr_x = (210 - $qr_size) / 2; 
        $borde_minimal = 1; 

        if (($y_qr + $qr_size) > 280) {
            $y_qr = 280 - $qr_size; 
        }

        $pdf->SetFillColor(255,255,255);
        $pdf->RoundedRect($qr_x - $borde_minimal, $y_qr, $qr_size + ($borde_minimal * 2), $qr_size + ($borde_minimal * 2), 2, '1111', 'F');
        
        // Imagen del QR
        $pdf->Image($qr_path, $qr_x, $y_qr + $borde_minimal, $qr_size, $qr_size);

        // --- GUARDADO Y CIERRE ---
        $slug = preg_replace('/[^a-z0-9]+/','-',strtolower(remove_accents($nombre_completo)));
        $nombre_archivo = 'entrada_'.$post_id.'_'.$slug.'_'.time().'.pdf';
        $pdf_full_path = $upload_dir['basedir'].'/'.$nombre_archivo;
        
        // Guardar el archivo en el servidor
        $pdf->Output($pdf_full_path, 'F');

        // Limpieza de QR temporal
        @unlink($qr_path);

    } catch (Exception $e) {
        error_log('❌ Error en Plugin QR (Evento ID '.$post_id.'): '.$e->getMessage());
    }
}
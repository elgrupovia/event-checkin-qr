<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR, registra asistentes y sincroniza con Zoho CRM (módulo "Eventos").
 * Version: 1.1
 * 
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

/**
 * ---------------------------
 * Helper Functions
 * ---------------------------
 */
function normalizar_texto($texto) {
    $texto = mb_strtolower($texto, 'UTF-8');
    $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    $texto = preg_replace('/\s+/', ' ', trim($texto));
    $texto = preg_replace('/[^a-z0-9\s\-]/', '', $texto);
    return $texto;
}

function primeras_palabras($texto, $limite = 3) {
    $texto = trim(preg_replace('/\s+/', ' ', $texto));
    $palabras = explode(' ', $texto);
    return implode(' ', array_slice($palabras, 0, $limite));
}

function buscar_evento_robusto($titulo_buscado) {
    $primeras = primeras_palabras($titulo_buscado, 3);
    $ciudades = ['barcelona','valencia','madrid','bilbao'];
    $ciudad_form = null;
    $normForm = normalizar_texto($titulo_buscado);

    foreach($ciudades as $c){
        if(stripos($normForm, normalizar_texto($c)) !== false){
            $ciudad_form = $c; break;
        }
    }

    $args = [
        'post_type' => 'eventos',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
        's' => $primeras,
        'tax_query' => [
            'relation' => 'AND',
            [
                'taxonomy' => 'ano',
                'field' => 'slug',
                'terms' => ['2025'],
            ],
            [
                'taxonomy' => 'ciudades',
                'field' => 'slug',
                'terms' => $ciudades,
            ],
        ],
    ];

    $eventos = get_posts($args);
    $event_id = 0;

    if(!empty($eventos) && $ciudad_form){
        $ciudad_buscar = normalizar_texto($ciudad_form);
        foreach($eventos as $evento){
            $titulo_evento_norm = normalizar_texto(get_the_title($evento->ID));
            if(stripos($titulo_evento_norm,$ciudad_buscar)!==false){
                $event_id = $evento->ID; break;
            }
        }
    }

    if($event_id===0){
        $eventos_all = get_posts(['post_type'=>'eventos','post_status'=>'publish','posts_per_page'=>-1]);
        foreach($eventos_all as $evento){
            if(stripos(normalizar_texto(get_the_title($evento->ID)), normalizar_texto($titulo_buscado))!==false){
                $event_id = $evento->ID; break;
            }
        }
    }
    return $event_id;
}

function optimizar_imagen_para_pdf($imagen_url, $upload_dir){
    $tmp = null; $imagen_path = '';
    $attachment_id = attachment_url_to_postid($imagen_url);
    if($attachment_id){
        $imagen_meta = wp_get_attachment_metadata($attachment_id);
        if($imagen_meta){
            $imagen_path = $upload_dir['basedir'].'/'.$imagen_meta['file'];
        }
    }
    if(!file_exists($imagen_path) && function_exists('download_url')){
        $tmp = download_url($imagen_url,300);
        if(!is_wp_error($tmp)) $imagen_path = $tmp;
    }
    return ['path'=>$imagen_path,'tmp'=>$tmp];
}

/**
 * ---------------------------
 * Generar PDF con QR
 * ---------------------------
 */
add_action('jet-form-builder/custom-action/inscripciones_qr','generar_qr_pdf_personalizado',10,3);
function generar_qr_pdf_personalizado($request, $action_handler) {
    try {
        // === LOG INICIAL ===
        error_log("=== [INSCRIPCIÓN INICIADA] ===");
        error_log("Datos recibidos: " . print_r($request, true));

        $nombre_empresa = sanitize_text_field($request['nombre_de_empresa'] ?? 'Empresa Desconocida');
        $nombre_persona = sanitize_text_field($request['nombre'] ?? 'Invitado');
        $apellidos_persona = sanitize_text_field($request['apellidos'] ?? $request['last_name'] ?? '');
        if (!$apellidos_persona && is_user_logged_in()) {
            $user = wp_get_current_user();
            $apellidos_persona = $user->last_name ?? '';
        }
        $cargo_persona = sanitize_text_field($request['cargo'] ?? 'Cargo no especificado');
        $nombre_completo = html_entity_decode(trim($nombre_persona . ' ' . $apellidos_persona), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // === EVENTO DEL FORMULARIO ===
        $titulo_evento_formulario = sanitize_text_field($request['eventos_2025'][0] ?? '');
        error_log("Título de evento desde formulario: " . $titulo_evento_formulario);

        $post_id = $titulo_evento_formulario ? buscar_evento_robusto($titulo_evento_formulario) : null;
        error_log("Evento detectado ID: " . ($post_id ?: 'No encontrado'));

        $titulo_a_mostrar = $post_id ? get_the_title($post_id) : ($titulo_evento_formulario ?: 'Evento no identificado');
        $titulo_a_mostrar = html_entity_decode($titulo_a_mostrar, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        error_log("Título del evento final usado: " . $titulo_a_mostrar);

        $ubicacion = get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Ubicación no disponible';
        $fecha_evento = get_post_meta($post_id, 'fecha', true);
        if (is_numeric($fecha_evento)) $fecha_evento = date('d/m/Y H:i', $fecha_evento);

        // === QR URL ===
        $base_url = home_url('/checkin/');
        $params = [
            'empresa' => rawurlencode($nombre_empresa),
            'nombre' => rawurlencode($nombre_completo),
            'cargo' => rawurlencode($cargo_persona),
            'email' => rawurlencode($request['email']), 
            'evento' => rawurlencode($titulo_a_mostrar),
            'ubicacion' => rawurlencode($ubicacion),
            'fecha' => rawurlencode($fecha_evento),
        ];
        $qr_url = $base_url . '?' . implode('&', array_map(fn($k, $v) => "$k=$v", array_keys($params), $params));

        error_log("QR URL generada: " . $qr_url);

        // === Generar QR ===
        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($qr_url)
            ->size(400)
            ->margin(15)
            ->build();

        $upload_dir = wp_upload_dir();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);

        // === Generar PDF ===
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCompression(false);
        $pdf->SetImageScale(4);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 20, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();

        $logo_path = plugin_dir_path(__FILE__) . '../assets/LOGO_GRUPO_VIA_RGB__NEGRO.jpg';
        if (file_exists($logo_path)) $pdf->Image($logo_path, 85, 8, 35, '', 'JPG', '', 'T', false, 300);

        $imagen_insertada = false;
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
                $imagen_path = $imagen_info['path'];
                if (file_exists($imagen_path)) {
                    $pdf->Image($imagen_path, (210 - 150) / 2, 30, 150, '', '', 'T', false, 300);
                    $imagen_insertada = true;
                }
            }
        }

        $pdf->SetY($imagen_insertada ? 115 : 60);
        $pdf->SetFont('helvetica', 'B', 22);
        $pdf->Cell(0, 14, 'ENTRADA CONFIRMADA', 0, 1, 'C');
        $pdf->Ln(8);

        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->MultiCell(0, 7, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->MultiCell(0, 5, htmlspecialchars($ubicacion, ENT_QUOTES, 'UTF-8'), 0, 'C');
        $pdf->MultiCell(0, 5, htmlspecialchars($fecha_evento, ENT_QUOTES, 'UTF-8'), 0, 'C');
        $pdf->Ln(10);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 10);

        $pdf->SetX(35);
        $pdf->Write(6, 'Empresa: ');
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->MultiCell(0, 6, $nombre_empresa, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);

        $pdf->SetX(35);
        $pdf->Write(6, 'Nombre: ');
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->MultiCell(0, 6, $nombre_completo, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);

        $pdf->SetX(35);
        $pdf->Write(6, 'Cargo: ');
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->MultiCell(0, 6, $cargo_persona, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);

        $pdf->Ln(8);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(0, 4, 'CÓDIGO DE ESCANEO', 0, 1, 'C');
        $pdf->Ln(4);

        $pdf->Image($qr_path, (210 - 65) / 2, $pdf->GetY(), 65, 65, 'PNG', '', '', true, 300);

        $pdf_filename = 'entrada_' . preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $nombre_completo) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        @unlink($qr_path);

        /**
         * ✅ Registrar asistente localmente en post meta
         */
        if ($post_id) {
            $asistentes = get_post_meta($post_id, '_asistentes', true);
            if (!is_array($asistentes)) $asistentes = [];

            $nuevo_asistente = [
                'nombre' => $nombre_completo,
                'empresa' => $nombre_empresa,
                'cargo' => $cargo_persona,
                'fecha_hora' => current_time('mysql')
            ];
            $asistentes[] = $nuevo_asistente;

            update_post_meta($post_id, '_asistentes', $asistentes);

            // === LOG DEL ASISTENTE GUARDADO ===
            error_log("Asistente guardado en evento ID {$post_id}: " . print_r($nuevo_asistente, true));
        } else {
            error_log("⚠️ No se guardó asistente porque no se detectó el evento.");
        }

        error_log("=== [INSCRIPCIÓN FINALIZADA] ===");

        /**
         * ---------------------------
         * 🔗 SINCRONIZACIÓN CON ZOHO CRM
         * (se ejecuta aunque el asistente ya haya sido guardado localmente)
         * ---------------------------
         */
        // Ruta: ajusta si es necesario. Asume /zoho/ dentro del plugin.
        $zoho_dir = dirname(plugin_dir_path(__FILE__)) . '/zoho';
        if (file_exists($zoho_dir . '/contacts.php')) {
            try {
                require_once $zoho_dir . '/config.php';   // contiene getAccessToken / refresh
                require_once $zoho_dir . '/contacts.php'; // searchContactByEmail, createContact, etc.

                // Datos para Zoho
                $email = sanitize_email($request['email'] ?? '');
                error_log("=== [ZOHO SYNC INICIADA] ===");
                error_log("Buscando contacto por email: " . $email);

                if ($email) {
                    // Buscar contacto
                    $search = null;
                    if (function_exists('searchContactByEmail')) {
                        $search = searchContactByEmail($email);
                    } else {
                        error_log("⚠️ searchContactByEmail() no existe en contacts.php");
                    }

                    $contactId = null;
                    if (!empty($search) && !empty($search['data'][0]['id'])) {
                        $contactId = $search['data'][0]['id'];
                        error_log("Contacto encontrado en Zoho: $contactId");
                    } else {
                        // Crear (si existe createContact)
                        if (function_exists('createContact')) {
                            $newContactPayload = [
                                "data" => [[
                                    "First_Name" => $nombre_persona,
                                    "Last_Name"  => $apellidos_persona ?: $nombre_persona,
                                    "Email"      => $email,
                                    "Company"    => $nombre_empresa,
                                    "Title"      => $cargo_persona,
                                ]]
                            ];
                            $created = createContact($newContactPayload);
                            if (!empty($created['data'][0]['details']['id'])) {
                                $contactId = $created['data'][0]['details']['id'];
                                error_log("✅ Contacto creado en Zoho con ID: $contactId");
                            } else {
                                error_log("⚠️ Error al crear contacto en Zoho: " . print_r($created, true));
                            }
                        } else {
                            error_log("⚠️ createContact() no existe en contacts.php");
                        }
                    }

                    // Relacionar con evento en Zoho
                    if ($contactId) {
                        // Helper local (definidas a continuación) getEventoIdFromZoho() y relateContactToEvento()
                        $eventoNombre = $titulo_a_mostrar ?? '';
                        $eventoIdZoho = getEventoIdFromZoho($eventoNombre);
                        if ($eventoIdZoho) {
                            $rel = relateContactToEvento($contactId, $eventoIdZoho);
                            error_log("Relación contact-evento Zoho respuesta: " . print_r($rel, true));
                        } else {
                            error_log("⚠️ No se encontró evento '$eventoNombre' en Zoho.");
                        }
                    }

                } else {
                    error_log("⚠️ No se proporcionó correo electrónico en el formulario; saltando sincronización Zoho.");
                }

                error_log("=== [ZOHO SYNC FINALIZADA] ===");

            } catch (Exception $e) {
                error_log("❌ Error en sincronización Zoho (catch): " . $e->getMessage());
            }
        } else {
            error_log("⚠️ Carpeta zoho o contacts.php no encontrada en: $zoho_dir");
        }

    } catch (Exception $e) {
        error_log("❌ Error PDF/Registro: " . $e->getMessage());
    }
}

/**
 * ---------------------------
 * Registrar QR leídos (Check-in)
 * ---------------------------
 */
add_action('template_redirect', function(){
    if(strpos($_SERVER['REQUEST_URI'],'/checkin/')!==false){
        $nombre = sanitize_text_field($_GET['nombre'] ?? 'Invitado');
        $empresa = sanitize_text_field($_GET['empresa'] ?? '');
        $cargo = sanitize_text_field($_GET['cargo'] ?? '');
        $evento = sanitize_text_field($_GET['evento'] ?? '');
        $ubicacion = sanitize_text_field($_GET['ubicacion'] ?? '');
        $fecha = sanitize_text_field($_GET['fecha'] ?? '');

        // LOG de lectura de QR
        error_log("=== [QR CHECKIN] ===");
        error_log("Datos recibidos por QR: " . print_r($_GET, true));

        $post_id = buscar_evento_robusto($evento);
        if($post_id){
            $asistentes = get_post_meta($post_id,'_asistentes',true);
            if(!is_array($asistentes)) $asistentes = [];
            $asistentes[] = [
                'nombre'=>$nombre,
                'empresa'=>$empresa,
                'cargo'=>$cargo,
                'fecha_hora'=>current_time('mysql')
            ];
            update_post_meta($post_id,'_asistentes',$asistentes);
            error_log("Asistente registrado por QR en evento {$post_id}: " . print_r(end($asistentes), true));
            // 🔗 Actualizar asistencia en Zoho CRM (campo "Asiste" = "Sí")
            require_once dirname(__FILE__) . '/../zoho/config.php';
            require_once dirname(__FILE__) . '/../zoho/contacts.php';
            require_once dirname(__FILE__) . '/../zoho/eventos.php';

            $email = sanitize_email($_GET['email'] ?? '');
            if ($email) {
                $busqueda = searchContactByEmail($email);
                if (isset($busqueda['data'][0]['id'])) {
                    $contactId = $busqueda['data'][0]['id'];
                    $eventZohoId = obtenerEventoZohoId($evento);
                    if ($eventZohoId) {
                        $marcado = marcarAsistenciaZoho($contactId, $eventZohoId);
                        error_log("✅ Asistencia marcada en Zoho para contacto {$contactId} en evento {$eventZohoId}");
                    } else {
                        error_log("⚠️ No se encontró el evento en Zoho para marcar asistencia: $evento");
                    }
                } else {
                    error_log("⚠️ No se encontró contacto en Zoho con el correo: $email");
                }
            } else {
                error_log("⚠️ No se recibió email en el QR, no se pudo marcar asistencia en Zoho.");
            }

        } else {
            error_log("⚠️ Evento no encontrado al hacer checkin: " . $evento);
        }

        echo "<h2>Check-in confirmado ✅</h2>";
        echo "<p>Bienvenido: <strong>" . esc_html($nombre) . "</strong></p>";
        echo "<p><strong>Empresa:</strong> " . esc_html($empresa) . "</p>";
        echo "<p><strong>Cargo:</strong> " . esc_html($cargo) . "</p>";
        echo "<p><strong>Evento:</strong> " . esc_html($evento) . "</p>";
        echo "<p><strong>Ubicación:</strong> " . esc_html($ubicacion) . "</p>";
        echo "<p><strong>Fecha del evento:</strong> " . esc_html($fecha) . "</p>";
        exit;
    }
});

/**
 * ---------------------------
 * Admin: Submenú Asistentes
 * ---------------------------
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=eventos',
        'Asistentes',
        'Asistentes',
        'manage_options',
        'eventos-asistentes',
        function() {
            echo '<div class="wrap"><h1>🧾 Asistentes por Evento</h1>';

            // Obtener todos los eventos publicados
            $eventos = get_posts([
                'post_type'   => 'eventos',
                'numberposts' => -1,
                'post_status' => 'publish',
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]);

            if (!$eventos) {
                echo '<p>No hay eventos publicados.</p></div>';
                return;
            }

            foreach ($eventos as $e) {
                $post_id = $e->ID;
                $titulo_evento = get_the_title($post_id);

                // === Recuperar asistentes (versión robusta) ===
                $meta_raw = get_post_meta($post_id, '_asistentes', false);
                $asistentes = [];

                if (!empty($meta_raw)) {
                    foreach ($meta_raw as $meta_val) {
                        $val = maybe_unserialize($meta_val);
                        if (is_array($val)) {
                            // Si es un array multidimensional (varios asistentes)
                            if (isset($val[0]) && is_array($val[0])) {
                                $asistentes = array_merge($asistentes, $val);
                            } else {
                                $asistentes[] = $val;
                            }
                        }
                    }
                }

                // === Logs para depurar ===
                error_log("📋 Evento listado en admin: {$post_id} - {$titulo_evento}");
                error_log("   Meta asistentes: " . print_r($asistentes, true));

                echo '<div style="margin-top:30px;padding:15px;border:1px solid #ddd;border-radius:10px;">';
                echo '<h2 style="margin-bottom:10px;">' . esc_html($titulo_evento) . '</h2>';

                if (!empty($asistentes)) {
                    echo '<table class="widefat striped" style="max-width:900px;">';
                    echo '<thead><tr>
                            <th>Nombre</th>
                            <th>Empresa</th>
                            <th>Cargo</th>
                            <th>Fecha / Hora Registro</th>
                          </tr></thead><tbody>';

                    foreach ($asistentes as $a) {
                        echo '<tr>';
                        echo '<td>' . esc_html($a['nombre'] ?? '') . '</td>';
                        echo '<td>' . esc_html($a['empresa'] ?? '') . '</td>';
                        echo '<td>' . esc_html($a['cargo'] ?? '') . '</td>';
                        echo '<td>' . esc_html($a['fecha_hora'] ?? '') . '</td>';
                        echo '</tr>';
                    }

                    echo '</tbody></table>';
                } else {
                    echo '<p style="color:#666;">No hay asistentes registrados aún.</p>';
                }

                echo '</div>';
            }

            echo '</div>'; // .wrap
        }
    );
});

/**
 * ---------------------------
 * Helper Zoho (locales para no tocar la librería Zoho)
 * ---------------------------
 */

/**
 * Buscar evento en Zoho CRM por nombre (módulo "Eventos")
 */
function getEventoIdFromZoho($nombreEvento) {
    // Intentamos usar getAccessToken() del config.php si existe
    if (function_exists('getAccessToken')) {
        $access_token = getAccessToken();
    } else {
        error_log("⚠️ getAccessToken() no encontrada. Asegúrate de tener zoho/config.php con esa función.");
        return null;
    }

    // Ajusta endpoint / criteria si tu campo para nombre tiene otro API name (ej: Event_Name)
    $criteria = "(Event_Name:equals:" . addslashes($nombreEvento) . ")";
    $url = "https://www.zohoapis.com/crm/v2/Eventos/search?criteria=" . urlencode($criteria);

    $headers = [
        "Authorization: Zoho-oauthtoken $access_token",
        "Content-Type: application/json"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    error_log("getEventoIdFromZoho response code: $httpcode, body: " . substr($response,0,1000));

    if (!empty($data['data'][0]['id'])) {
        error_log("Evento encontrado en Zoho: " . $data['data'][0]['id']);
        return $data['data'][0]['id'];
    } else {
        error_log("Evento no encontrado en Zoho para: $nombreEvento");
        return null;
    }
}

/**
 * Relacionar contacto con evento en Zoho CRM.
 * IMPORTANTE: Ajusta el endpoint/subform name según tu configuración Zoho.
 */
function relateContactToEvento($contactId, $eventoId) {
    if (function_exists('getAccessToken')) {
        $access_token = getAccessToken();
    } else {
        return ['error' => 'getAccessToken() not found'];
    }

    // ⚠️ AJUSTA esto: nombre del subform/relación de tu módulo Contacts en Zoho
    // Algunas instalaciones usan subform names o campos lookup. Cambia "Evento_Subform" por el API name real.
    $subform_name = 'Evento_Subform';

    $url = "https://www.zohoapis.com/crm/v2/Contacts/$contactId/$subform_name";

    $body = [
        "data" => [
            [
                // Esto depende de cómo esté construido tu subform o lookup
                // Si tu subform requiere campos particulares, ajusta aquí.
                "Evento" => ["id" => $eventoId]
            ]
        ]
    ];

    $headers = [
        "Authorization: Zoho-oauthtoken $access_token",
        "Content-Type: application/json"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("relateContactToEvento response code: $httpcode, body: " . substr($response,0,1000));
    $res = json_decode($response, true);
    return $res ?: ['error' => "No response", 'http_code' => $httpcode];
}

/**
 * Sincroniza registro de asistentes con Zoho CRM
 * - Busca contacto por email
 * - Crea contacto si no existe
 * - Busca evento en Zoho
 * - Crea relación Contacto ↔ Evento en "Contactos_vs_Eventos"
 * - Marca campo Asiste cuando se hace check-in
 */

require_once plugin_dir_path(__FILE__) . '../zoho/config.php';
require_once plugin_dir_path(__FILE__) . '../zoho/contacts.php';
require_once plugin_dir_path(__FILE__) . '../zoho/eventos.php';

function sync_with_zoho($email, $nombre, $apellidos, $empresa, $titulo_evento) {
    error_log("=== [ZOHO SYNC INICIADA] ===");

    // Buscar contacto existente
    $busqueda = searchContactByEmail($email);
    if (isset($busqueda['data'][0]['id'])) {
        $contactId = $busqueda['data'][0]['id'];
        error_log("✅ Contacto existente en Zoho: $contactId");
    } else {
        // Crear nuevo contacto
        $nuevo = createContactZoho([
            "First_Name" => $nombre,
            "Last_Name"  => $apellidos,
            "Email"      => $email,
            "Account_Name" => $empresa
        ]);
        $contactId = $nuevo['data'][0]['details']['id'] ?? null;
        error_log("🆕 Contacto creado en Zoho con ID: $contactId");
    }

    // Buscar evento en Zoho CRM (por su título)
    $eventZohoId = obtenerEventoZohoId($titulo_evento);
    if (!$eventZohoId) {
        error_log("⚠️ No se encontró el evento '$titulo_evento' en Zoho CRM.");
        return;
    }

    // Crear o actualizar la relación en Contactos_vs_Eventos
    $relation = createContactEventRelation($contactId, $eventZohoId, "No");
    error_log("🔗 Relación Contacto ↔ Evento creada/actualizada.");
    error_log(print_r($relation, true));

    error_log("=== [ZOHO SYNC FINALIZADA] ===");
}

/**
 * Busca un evento en Zoho CRM por su nombre
 */
function obtenerEventoZohoId($titulo_evento) {
    $access_token = getAccessToken();
    $url = "https://www.zohoapis.com/crm/v2/Eventos/search?criteria=(Eventos:equals:" . urlencode($titulo_evento) . ")";

    $headers = ["Authorization: Zoho-oauthtoken $access_token"];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (!empty($data['data'][0]['id'])) {
        return $data['data'][0]['id'];
    }
    return null;
}

/**
 * Crea una relación Contacto ↔ Evento en Zoho CRM
 */
function createContactEventRelation($contactId, $eventId, $asiste = "No") {
    $access_token = getAccessToken();
    $url = "https://www.zohoapis.com/crm/v2/Contactos_vs_Eventos";
    $headers = [
        "Authorization: Zoho-oauthtoken $access_token",
        "Content-Type: application/json"
    ];

    $body = [
        "data" => [[
            "Contactos" => ["id" => $contactId],
            "Eventos"   => ["id" => $eventId],
            "Asiste"    => $asiste
        ]]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

/**
 * Marca al contacto como asistente en el evento (cuando se escanea el QR)
 */
function marcarAsistenciaZoho($contactId, $eventId) {
    $access_token = getAccessToken();
    $url = "https://www.zohoapis.com/crm/v2/Contactos_vs_Eventos/search?criteria=(Contactos.id:equals:$contactId)and(Eventos.id:equals:$eventId)";
    $headers = ["Authorization: Zoho-oauthtoken $access_token"];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    $relationId = $data['data'][0]['id'] ?? null;
    if (!$relationId) return false;

    // Actualiza el campo Asiste = "Sí"
    $updateUrl = "https://www.zohoapis.com/crm/v2/Contactos_vs_Eventos/$relationId";
    $body = [
        "data" => [[
            "Asiste" => "Sí"
        ]]
    ];

    $ch = curl_init($updateUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ["Content-Type: application/json"]));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $updateResponse = curl_exec($ch);
    curl_close($ch);

    return json_decode($updateResponse, true);
}


?>

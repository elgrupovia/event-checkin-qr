<?php
/**
 * relations.php
 * Orquestación: Zoho (Evento/Contacto/Empresa) → WP (CPTs) → Relación JetEngine
 * - Evento ↔ Ponente (parent = Evento, child = Ponente)
 * - Empresa ↔ Ponente (parent = Empresa, child = Ponente) [si llega empresa]
 *
 * NOTA: El UNLINK se hace SOLO por SQL (gv_sql_unlink_relations).
 */

defined('ABSPATH') || exit;

// ==============================
// Config / Ajustes rápidos
// ==============================
if (!defined('GV_JE_RELATION_ID')) {
    // ID de la relación en JetEngine (Evento parent ↔ Ponente child)
    define('GV_JE_RELATION_ID', 27);
}
if (!defined('GV_JE_RELATION_EMPRESA_PONENTE_ID')) {
    // ID de la relación en JetEngine (Empresa parent ↔ Ponente child)
    define('GV_JE_RELATION_EMPRESA_PONENTE_ID', 28); // ⬅️ pon aquí el ID real
}

// Slugs de CPT
if (!defined('GV_CPT_EVENTO'))   define('GV_CPT_EVENTO', 'eventos');
if (!defined('GV_CPT_PONENTE'))  define('GV_CPT_PONENTE', 'ponentes');
if (!defined('GV_CPT_EMPRESA'))  define('GV_CPT_EMPRESA', 'empresas');

// Metas clave
if (!defined('GV_META_EVENTO_ZOHO_ID'))  define('GV_META_EVENTO_ZOHO_ID',  'id_zoho');
if (!defined('GV_META_PONENTE_ZOHO_ID')) define('GV_META_PONENTE_ZOHO_ID', 'id_zoho');
if (!defined('GV_META_EMPRESA_ZOHO_ID')) define('GV_META_EMPRESA_ZOHO_ID', 'id_zoho_empresa');

// ==============================
// Dependencias (helpers existentes)
// ==============================
require_once __DIR__ . '/eventos.php';
require_once __DIR__ . '/ponentes.php';
require_once __DIR__ . '/empresas.php';
require_once __DIR__ . '/config.php';

// ==============================
// Helpers HTTP / Seguridad
// ==============================
function gv_rel_headers() {
    $h = array('Content-Type' => 'application/json');
    if (defined('GV_REST_BASIC_USER') && defined('GV_REST_BASIC_PASS')) {
        $h['Authorization'] = 'Basic ' . base64_encode(GV_REST_BASIC_USER . ':' . GV_REST_BASIC_PASS);
    }
    return $h;
}

/**
 * Permiso básico por token (opcional):
 * - Define GV_WEBHOOK_TOKEN en config.php para forzar cabecera x-gv-token.
 * - Si no está definido, permite el acceso (útil para pruebas).
 */
function gv_permission_callback(WP_REST_Request $r) {
    if (defined('GV_WEBHOOK_TOKEN') && GV_WEBHOOK_TOKEN !== '') {
        $token = (string) $r->get_header('x-gv-token');
        return hash_equals(GV_WEBHOOK_TOKEN, $token);
    }
    return true; // ⚠️ abierto si no defines token
}

// ==============================
// Helpers de payload y búsqueda en WP
// ==============================
function gv_read_payload(WP_REST_Request $r) {
    $json = $r->get_json_params();
    if (is_array($json) && !empty($json)) return $json;

    $form = $r->get_body_params(); // form-data / x-www-form-urlencoded
    if (is_array($form) && !empty($form)) return $form;

    $all = $r->get_params(); // mezcla de todo
    return is_array($all) ? $all : array();
}

/** Devuelve el ID del CPT Evento por su ID de Zoho guardado en meta. */
function gv_find_event_post_by_zoho_id($zoho_event_id) {
    $zoho_event_id = trim((string) $zoho_event_id);
    if ($zoho_event_id === '') return 0;

    $q = new WP_Query(array(
        'post_type'      => array(GV_CPT_EVENTO),
        'post_status'    => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'   => GV_META_EVENTO_ZOHO_ID,
                'value' => $zoho_event_id,
            ),
        ),
        'no_found_rows'  => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    ));
    return !empty($q->posts) ? (int) $q->posts[0] : 0;
}

/** Devuelve el ID del CPT Evento por su nombre (título). Primero por slug, luego por título exacto. */
function gv_find_event_post_by_name($event_name) {
    $event_name = trim((string)$event_name);
    if ($event_name === '') return 0;

    $slug = sanitize_title($event_name);

    // A) Intento por slug exacto
    $q = new WP_Query(array(
        'post_type'      => array(GV_CPT_EVENTO),
        'name'           => $slug,
        'post_status'    => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    ));
    if (!empty($q->posts)) return (int)$q->posts[0];

    // B) Fallback: match exacto del título
    $q2 = new WP_Query(array(
        'post_type'      => array(GV_CPT_EVENTO),
        'post_status'    => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page' => 10,
        'fields'         => 'ids',
        's'              => $event_name,
        'no_found_rows'  => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    ));
    if (!empty($q2->posts)) {
        foreach ($q2->posts as $pid) {
            $title = get_the_title($pid);
            if (mb_strtolower(trim($title)) === mb_strtolower($event_name)) {
                return (int)$pid;
            }
        }
    }
    return 0;
}

/** Devuelve el ID del CPT Ponente por su ID de Zoho. */
function gv_find_speaker_post_by_zoho_id($zoho_contact_id) {
    $zoho_contact_id = trim((string) $zoho_contact_id);
    if ($zoho_contact_id === '') return 0;

    $q = new WP_Query(array(
        'post_type'      => array(GV_CPT_PONENTE),
        'post_status'    => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'   => GV_META_PONENTE_ZOHO_ID,
                'value' => $zoho_contact_id,
            ),
        ),
        'no_found_rows'  => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    ));
    return !empty($q->posts) ? (int)$q->posts[0] : 0;
}

/** Devuelve el ID del CPT Empresa por su ID de Zoho. */
function gv_find_company_post_by_zoho_id($zoho_account_id) {
    $zoho_account_id = trim((string) $zoho_account_id);
    if ($zoho_account_id === '') return 0;

    $q = new WP_Query(array(
        'post_type'      => array(GV_CPT_EMPRESA),
        'post_status'    => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'   => GV_META_EMPRESA_ZOHO_ID,
                'value' => $zoho_account_id,
            ),
        ),
        'no_found_rows'  => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    ));
    return !empty($q->posts) ? (int)$q->posts[0] : 0;
}

/** Devuelve el ID del CPT Evento a partir de su URL pública. */
function gv_find_event_post_by_url($event_url) {
    $event_url = trim((string)$event_url);
    if ($event_url === '') return 0;

    // Limpia query params/fragment y normaliza slash final
    $event_url = strtok($event_url, '?');
    $event_url = strtok($event_url, '#');
    $event_url = trailingslashit($event_url);

    // 1) intento directo
    $post_id = url_to_postid($event_url);
    if ($post_id > 0 && get_post_type($post_id) === GV_CPT_EVENTO) {
        return (int)$post_id;
    }

    // 2) fallback sin slash final
    $post_id = url_to_postid(untrailingslashit($event_url));
    if ($post_id > 0 && get_post_type($post_id) === GV_CPT_EVENTO) {
        return (int)$post_id;
    }

    // 3) por GUID
    global $wpdb;
    $maybe = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type=%s AND (guid=%s OR guid LIKE %s) LIMIT 1",
            GV_CPT_EVENTO, $event_url, $event_url . '%'
        )
    );
    return $maybe ? (int)$maybe : 0;
}

// ==============================
// Wrappers "upsert" (llaman a helpers si existen)
// ==============================
function gv_upsert_empresa_from_zoho($zoho_account_id) {
    $zoho_account_id = trim((string)$zoho_account_id);
    if ($zoho_account_id === '') return 0;

    $existing = gv_find_company_post_by_zoho_id($zoho_account_id);
    if ($existing) return $existing;

    foreach (array(
        'gv_sync_empresa_by_zoho_id',
        'gv_create_or_update_empresa_from_zoho',
        'upsert_empresa_from_zoho',
        'create_empresa_from_zoho',
    ) as $fn) {
        if (function_exists($fn)) {
            $id = (int) call_user_func($fn, $zoho_account_id);
            if ($id > 0) return $id;
        }
    }
    return 0;
}

function gv_upsert_ponente_from_zoho($zoho_contact_id, $wp_company_id = 0) {
    $zoho_contact_id = trim((string)$zoho_contact_id);
    if ($zoho_contact_id === '') return 0;

    $existing = gv_find_speaker_post_by_zoho_id($zoho_contact_id);
    if ($existing) return $existing;

    foreach (array(
        'gv_sync_ponente_by_zoho_id',
        'gv_create_or_update_ponente_from_zoho',
        'upsert_ponente_from_zoho',
        'create_ponente_from_zoho',
    ) as $fn) {
        if (function_exists($fn)) {
            $ref = new ReflectionFunction($fn);
            $params = $ref->getNumberOfParameters();
            $id = ($params >= 2)
                ? (int) call_user_func($fn, $zoho_contact_id, (int)$wp_company_id)
                : (int) call_user_func($fn, $zoho_contact_id);
            if ($id > 0) return $id;
        }
    }
    return 0;
}

function gv_upsert_evento_from_zoho($zoho_event_id) {
    $zoho_event_id = trim((string)$zoho_event_id);
    if ($zoho_event_id === '') return 0;

    $existing = gv_find_event_post_by_zoho_id($zoho_event_id);
    if ($existing) return $existing;

    foreach (array(
        'gv_sync_evento_by_zoho_id',
        'gv_create_or_update_evento_from_zoho',
        'upsert_evento_from_zoho',
        'create_evento_from_zoho',
    ) as $fn) {
        if (function_exists($fn)) {
            $id = (int) call_user_func($fn, $zoho_event_id);
            if ($id > 0) return $id;
        }
    }
    return 0;
}

// ==============================
// JetEngine: helpers de relación (SOLO para LINK)
// ==============================

/**
 * Normaliza children desde múltiples formatos posibles del endpoint REST de JetEngine.
 * - Tu caso: {"<parent_id>":[{"child_object_id":"53821"}, ...]}
 * - Otros: {items:[{child_id:...}]}, {children:[...]}
 */
function gv_jetengine_extract_children($json, $parent_id) {
    $parent_id = (int) $parent_id;
    $children = array();

    if (!is_array($json)) return $children;

    // A) Formato con clave = parent_id
    $key = (string)$parent_id;
    if (isset($json[$key]) && is_array($json[$key])) {
        foreach ($json[$key] as $row) {
            if (isset($row['child_object_id'])) $children[] = (int)$row['child_object_id'];
            elseif (isset($row['child_id']))    $children[] = (int)$row['child_id'];
            elseif (isset($row['child']))       $children[] = (int)$row['child'];
            elseif (isset($row['id']))          $children[] = (int)$row['id'];
        }
        return array_values(array_unique(array_filter($children)));
    }

    // B) Formatos habituales
    if (isset($json['items']) && is_array($json['items'])) {
        foreach ($json['items'] as $item) {
            if (isset($item['child_object_id'])) $children[] = (int)$item['child_object_id'];
            elseif (isset($item['child_id']))    $children[] = (int)$item['child_id'];
            elseif (isset($item['child']))       $children[] = (int)$item['child'];
            elseif (isset($item['id']))          $children[] = (int)$item['id'];
        }
    } elseif (isset($json['children']) && is_array($json['children'])) {
        foreach ($json['children'] as $child) $children[] = (int)$child;
    }

    return array_values(array_unique(array_filter($children)));
}

/** Comprueba si ya existe la relación (parent ↔ child). */
function gv_jetengine_relation_exists($relation_id, $wp_parent_id, $wp_child_id) {
    $relation_id   = (int)$relation_id;
    $wp_parent_id  = (int)$wp_parent_id;
    $wp_child_id   = (int)$wp_child_id;
    if ($relation_id <= 0 || $wp_parent_id <= 0 || $wp_child_id <= 0) return false;

    $endpoint = trailingslashit(home_url()) . 'wp-json/jet-rel/' . $relation_id;
    $url = add_query_arg(array(
        'parent_id' => $wp_parent_id,
        'context'   => 'parent',
    ), $endpoint);

    $res = wp_remote_get($url, array(
        'timeout' => 15,
        'headers' => gv_rel_headers(),
    ));
    if (is_wp_error($res)) return false;

    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) return false;

    $json = json_decode(wp_remote_retrieve_body($res), true);
    $children = gv_jetengine_extract_children($json, $wp_parent_id);

    return in_array($wp_child_id, $children, true);
}

/**
 * Añade/actualiza la relación (no reemplaza la lista existente).
 */
function gv_jetengine_add_relation($relation_id, $wp_parent_id, $wp_child_id, $meta = array()) {
    $relation_id   = (int)$relation_id;
    $wp_parent_id  = (int)$wp_parent_id;
    $wp_child_id   = (int)$wp_child_id;
    if ($relation_id <= 0 || $wp_parent_id <= 0 || $wp_child_id <= 0) {
        return array('status' => 'error', 'error' => 'Parámetros inválidos para JetEngine.');
    }

    $endpoint = trailingslashit(home_url()) . 'wp-json/jet-rel/' . $relation_id;

    $body = array(
        'parent_id'        => $wp_parent_id,
        'child_id'         => $wp_child_id,
        'context'          => 'parent',
        'store_items_type' => 'update', // añadir sin machacar
        'meta'             => is_array($meta) ? $meta : array(),
    );

    $res = wp_remote_post($endpoint, array(
        'timeout' => 20,
        'headers' => gv_rel_headers(),
        'body'    => wp_json_encode($body),
    ));
    if (is_wp_error($res)) {
        return array('status' => 'error', 'error' => $res->get_error_message());
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $resp_body = wp_remote_retrieve_body($res);

    if ($code >= 200 && $code < 300) {
        return array('status' => 'linked', 'response' => $resp_body);
    }

    return array('status' => 'error', 'error' => 'HTTP ' . $code, 'response' => $resp_body);
}

/** Idempotente: si no existe, crea. */
function gv_jetengine_link_event_speaker($relation_id, $wp_parent_id, $wp_child_id, $meta = array()) {
    if (gv_jetengine_relation_exists($relation_id, $wp_parent_id, $wp_child_id)) {
        return array('status' => 'already_linked');
    }
    return gv_jetengine_add_relation($relation_id, $wp_parent_id, $wp_child_id, $meta);
}

// ==============================
// SQL unlink helpers (solo SQL, dinámico)
// ==============================
function gv_rel_get_table() {
    global $wpdb;
    $t_default = $wpdb->prefix . 'jet_rel_default';
    $t_rel     = $wpdb->prefix . 'jet_relations';

    $has_default = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $t_default) );
    if ($has_default === $t_default) return $t_default;

    $has_rel = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $t_rel) );
    if ($has_rel === $t_rel) return $t_rel;

    return '';
}

/**
 * Borra relaciones por SQL de forma segura (UN link o varios).
 * - $rel_id: ID relación (27 evento↔ponente, 28 empresa↔ponente, etc.)
 * - $parent_id: post padre (evento/empresa)
 * - $child_ids: array de hijos (si vacío y $all_children=true → borra todos)
 * - $all_children: true → ignora $child_ids y borra todos los hijos del parent
 */
function gv_sql_unlink_relations($rel_id, $parent_id, array $child_ids = array(), $all_children = false) {
    global $wpdb;

    $rel_id    = (int) $rel_id;
    $parent_id = (int) $parent_id;
    $child_ids = array_values(array_unique(array_map('intval', $child_ids)));

    $table = gv_rel_get_table();
    if (!$table) {
        return array('success'=>false,'error'=>'No se encontró tabla JetEngine (jet_rel_default / jet_relations).');
    }
    if (!$rel_id || !$parent_id) {
        return array('success'=>false,'error'=>'Parámetros inválidos (rel_id/parent_id).');
    }
    if (!$all_children && empty($child_ids)) {
        return array('success'=>false,'error'=>'Debes pasar child_ids o activar all_children=true.');
    }

    $wpdb->query('START TRANSACTION');
    try {
        $affected = 0;

        if ($all_children) {
            $deleted = $wpdb->delete(
                $table,
                array('rel_id' => $rel_id, 'parent_object_id' => $parent_id),
                array('%d','%d')
            );
            $affected = max(0, (int)$deleted);
        } else {
            $chunks = array_chunk($child_ids, 1000);
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                $sql = "
                    DELETE FROM {$table}
                    WHERE rel_id = %d
                      AND parent_object_id = %d
                      AND child_object_id IN ($placeholders)
                ";
                $params = array_merge(array($rel_id, $parent_id), $chunk);
                $res = $wpdb->query( $wpdb->prepare($sql, $params) );
                if ($res !== false) $affected += (int)$res;
            }
        }

        $wpdb->query('COMMIT');

        // Limpia caches
        clean_post_cache($parent_id);
        if (!$all_children) {
            foreach ($child_ids as $cid) if ($cid > 0) clean_post_cache($cid);
        }

        return array(
            'success'  => true,
            'status'   => $affected > 0 ? 'unlinked' : 'already_removed',
            'affected' => $affected,
            'table'    => $table,
        );
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return array('success'=>false,'error'=>$e->getMessage());
    }
}

// ==============================
// Orquestador principal (POST crear/enlazar)
// ==============================

/**
 * Procesa el payload del webhook: asegura Empresa, Ponente y Evento (por ID Zoho, URL o nombre),
 * y crea la relación JetEngine Evento ↔ Ponente y Empresa ↔ Ponente (si hay empresa).
 */
function gv_process_zoho_relation_payload(array $data) {

    // 1) Extraer IDs/campos del payload (tolerante)
    $zoho_event_id   = '';
    $zoho_contact_id = '';
    $zoho_account_id = '';
    $event_name      = '';
    $event_web_url   = '';

    if (isset($data['event_web_url'])) $event_web_url = trim((string)$data['event_web_url']);

    if (isset($data['zoho_event_id']))   $zoho_event_id   = $data['zoho_event_id'];
    if (isset($data['event_id']))        $zoho_event_id   = $zoho_event_id ?: $data['event_id'];
    if (isset($data['Evento']['id']))    $zoho_event_id   = $zoho_event_id ?: $data['Evento']['id'];

    if (isset($data['nombre_evento']))   $event_name      = $data['nombre_evento'];
    if (!$event_name && isset($data['Evento']['name'])) $event_name = $data['Evento']['name'];

    if (isset($data['zoho_contact_id'])) $zoho_contact_id = $data['zoho_contact_id'];
    if (isset($data['contact_id']))      $zoho_contact_id = $zoho_contact_id ?: $data['contact_id'];
    if (isset($data['Ponente']['id']))   $zoho_contact_id = $zoho_contact_id ?: $data['Ponente']['id'];

    if (isset($data['zoho_account_id'])) $zoho_account_id = $data['zoho_account_id'];
    if (isset($data['account_id']))      $zoho_account_id = $zoho_account_id ?: $data['account_id'];
    if (isset($data['Empresa']['id']))   $zoho_account_id = $zoho_account_id ?: $data['Empresa']['id'];

    $zoho_event_id   = trim((string)$zoho_event_id);
    $zoho_contact_id = trim((string)$zoho_contact_id);
    $zoho_account_id = trim((string)$zoho_account_id);
    $event_name      = trim((string)$event_name);

    // Validación barata de IDs
    if ($zoho_contact_id !== '' && !ctype_digit($zoho_contact_id)) return array('success'=>false,'error'=>'zoho_contact_id inválido');
    if ($zoho_event_id   !== '' && !ctype_digit($zoho_event_id))   return array('success'=>false,'error'=>'zoho_event_id inválido');
    if ($zoho_account_id !== '' && !ctype_digit($zoho_account_id)) return array('success'=>false,'error'=>'zoho_account_id inválido');

    // Validación mínima
    if (($zoho_event_id === '' && $event_name === '' && $event_web_url === '') || $zoho_contact_id === '') {
        return array(
            'success' => false,
            'error'   => 'Faltan datos: necesitas (id_zoho_evento o nombre_evento o event_web_url) y el id del ponente.',
            'input'   => compact('zoho_event_id','event_name','event_web_url','zoho_contact_id','zoho_account_id'),
        );
    }

    // Validar dominio de event_web_url (si llega)
    if (!empty($event_web_url)) {
        $host_in  = wp_parse_url($event_web_url, PHP_URL_HOST);
        $host_own = wp_parse_url(home_url(), PHP_URL_HOST);
        if ($host_in && $host_own && strcasecmp($host_in, $host_own) !== 0) {
            return array('success' => false, 'error' => 'event_web_url inválida (dominio no permitido)');
        }
    }

    // 2) Asegurar Empresa
    $wp_company_id = 0;
    if ($zoho_account_id !== '') {
        $wp_company_id = gv_upsert_empresa_from_zoho($zoho_account_id);
        if (!$wp_company_id) $wp_company_id = gv_find_company_post_by_zoho_id($zoho_account_id);
    }

    // 3) Asegurar Ponente
    $wp_speaker_id = gv_upsert_ponente_from_zoho($zoho_contact_id, $wp_company_id);
    if (!$wp_speaker_id) $wp_speaker_id = gv_find_speaker_post_by_zoho_id($zoho_contact_id);
    if (!$wp_speaker_id) return array('success'=>false,'error'=>'No se pudo asegurar el Ponente en WP','zoho_contact_id'=>$zoho_contact_id);

    // 3.b) Relación Empresa ↔ Ponente
    $company_rel = null;
    if ($wp_company_id > 0 && defined('GV_JE_RELATION_EMPRESA_PONENTE_ID') && GV_JE_RELATION_EMPRESA_PONENTE_ID > 0) {
        $company_rel = gv_jetengine_link_event_speaker(GV_JE_RELATION_EMPRESA_PONENTE_ID, $wp_company_id, $wp_speaker_id, array());
    }

    // 4) Asegurar/Encontrar Evento (ID Zoho → URL → nombre)
    $wp_event_id = 0;
    if ($zoho_event_id !== '') {
        $wp_event_id = gv_find_event_post_by_zoho_id($zoho_event_id);
        if (!$wp_event_id) {
            $wp_event_id = gv_upsert_evento_from_zoho($zoho_event_id);
            if (!$wp_event_id) $wp_event_id = gv_find_event_post_by_zoho_id($zoho_event_id);
        }
    }
    if (!$wp_event_id && $event_web_url !== '') {
        $wp_event_id = gv_find_event_post_by_url($event_web_url);
        if ($wp_event_id && $zoho_event_id !== '') {
            $current = get_post_meta($wp_event_id, GV_META_EVENTO_ZOHO_ID, true);
            if (!$current) update_post_meta($wp_event_id, GV_META_EVENTO_ZOHO_ID, $zoho_event_id);
        }
    }
    if (!$wp_event_id && $event_name !== '') {
        $wp_event_id = gv_find_event_post_by_name($event_name);
    }
    if (!$wp_event_id) {
        return array('success'=>false,'error'=>'Evento no encontrado (ID Zoho / URL / nombre).','zoho_event_id'=>$zoho_event_id,'event_web_url'=>$event_web_url,'event_name'=>$event_name);
    }

    // 5) Link Evento ↔ Ponente (vía JetEngine REST)
    $rel = gv_jetengine_link_event_speaker(GV_JE_RELATION_ID, $wp_event_id, $wp_speaker_id, array());

    return array(
        'success'         => ($rel['status'] ?? '') !== 'error',
        'status'          => $rel['status'] ?? 'unknown',
        'zoho_event_id'   => $zoho_event_id,
        'event_name'      => $event_name,
        'event_web_url'   => $event_web_url,
        'zoho_contact_id' => $zoho_contact_id,
        'zoho_account_id' => $zoho_account_id,
        'wp_event_id'     => (int)$wp_event_id,
        'wp_speaker_id'   => (int)$wp_speaker_id,
        'wp_company_id'   => (int)$wp_company_id,
        'relation'        => $rel,
        'company_relation'=> $company_rel,
    );
}

// ==============================
// Orquestador UNLINK (POST) (solo SQL)
// ==============================

/**
 * Desenlaza la relación Evento (parent) ↔ Ponente (child) con SQL directo.
 * Requiere: zoho_contact_id y uno de (zoho_event_id | event_web_url | nombre_evento).
 */
function gv_process_zoho_unlink_event_speaker(array $data) {

    $zoho_contact_id = '';
    $zoho_event_id   = '';
    $event_web_url   = '';
    $event_name      = '';

    if (isset($data['zoho_contact_id'])) $zoho_contact_id = trim((string)$data['zoho_contact_id']);
    if (isset($data['zoho_event_id']))   $zoho_event_id   = trim((string)$data['zoho_event_id']);
    if (isset($data['event_id']))        $zoho_event_id   = $zoho_event_id ?: trim((string)$data['event_id']);
    if (isset($data['Evento']['id']))    $zoho_event_id   = $zoho_event_id ?: trim((string)$data['Evento']['id']);
    if (isset($data['event_web_url']))   $event_web_url   = trim((string)$data['event_web_url']);
    if (isset($data['nombre_evento']))   $event_name      = trim((string)$data['nombre_evento']);
    if (!$event_name && isset($data['Evento']['name'])) $event_name = trim((string)$data['Evento']['name']);

    // Validación barata
    if ($zoho_contact_id !== '' && !ctype_digit($zoho_contact_id)) return array('success'=>false,'error'=>'zoho_contact_id inválido');
    if ($zoho_event_id   !== '' && !ctype_digit($zoho_event_id))   return array('success'=>false,'error'=>'zoho_event_id inválido');

    // Mínimos
    if ($zoho_contact_id === '' || ($zoho_event_id === '' && $event_web_url === '' && $event_name === '')) {
        return array('success'=>false,'error'=>'Faltan datos: zoho_contact_id y (zoho_event_id o event_web_url o nombre_evento).','input'=>compact('zoho_contact_id','zoho_event_id','event_web_url','event_name'));
    }

    // Validar dominio (si llega URL)
    if (!empty($event_web_url)) {
        $host_in  = wp_parse_url($event_web_url, PHP_URL_HOST);
        $host_own = wp_parse_url(home_url(), PHP_URL_HOST);
        if ($host_in && $host_own && strcasecmp($host_in, $host_own) !== 0) {
            return array('success' => false, 'error' => 'event_web_url inválida (dominio no permitido)');
        }
    }

    // Resolver IDs WP
    $wp_speaker_id = gv_find_speaker_post_by_zoho_id($zoho_contact_id);
    if (!$wp_speaker_id) return array('success'=>false,'error'=>'Ponente no encontrado en WP','zoho_contact_id'=>$zoho_contact_id);

    $wp_event_id = 0;
    if ($zoho_event_id !== '') $wp_event_id = gv_find_event_post_by_zoho_id($zoho_event_id);
    if (!$wp_event_id && $event_web_url !== '') $wp_event_id = gv_find_event_post_by_url($event_web_url);
    if (!$wp_event_id && $event_name !== '')    $wp_event_id = gv_find_event_post_by_name($event_name);
    if (!$wp_event_id) {
        return array('success'=>false,'error'=>'Evento no encontrado (ID Zoho / URL / nombre).','zoho_event_id'=>$zoho_event_id,'event_web_url'=>$event_web_url,'event_name'=>$event_name);
    }

    // Unlink DIRECTO por SQL (Evento parent ↔ Ponente child)
    $sql = gv_sql_unlink_relations((int)GV_JE_RELATION_ID, (int)$wp_event_id, array((int)$wp_speaker_id), false);

    return array(
        'success'        => !empty($sql['success']),
        'status'         => $sql['status'] ?? 'error',
        'zoho_contact_id'=> $zoho_contact_id,
        'zoho_event_id'  => $zoho_event_id,
        'event_web_url'  => $event_web_url,
        'event_name'     => $event_name,
        'wp_speaker_id'  => (int)$wp_speaker_id,
        'wp_event_id'    => (int)$wp_event_id,
        'result'         => $sql,
    );
}

// ==============================
// Registro de endpoints
// ==============================
add_action('rest_api_init', function () {
    register_rest_route('gv/v1', '/zoho-relations', array(
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $data = gv_read_payload($request); // acepta JSON y form-data
            if (!is_array($data) || empty($data)) {
                return new WP_REST_Response(array('success' => false, 'error' => 'Payload inválido'), 400);
            }

            if (!function_exists('gv_process_zoho_relation_payload')) {
                return new WP_REST_Response(array('success' => false, 'error' => 'relations.php no cargado'), 500);
            }

            $result = gv_process_zoho_relation_payload($data);
            $code = (!empty($result['success'])) ? 200 : 400;
            return new WP_REST_Response($result, $code);
        },
        'permission_callback' => 'gv_permission_callback', // define GV_WEBHOOK_TOKEN para proteger
    ));
});

add_action('rest_api_init', function () {
    register_rest_route('gv/v1', '/zoho-relations/unlink', array(
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $data = gv_read_payload($request);
            if (!is_array($data) || empty($data)) {
                return new WP_REST_Response(array('success' => false, 'error' => 'Payload inválido'), 400);
            }

            if (!function_exists('gv_process_zoho_unlink_event_speaker')) {
                return new WP_REST_Response(array('success' => false, 'error' => 'relations.php no cargado (unlink)'), 500);
            }

            $result = gv_process_zoho_unlink_event_speaker($data);
            $code = (!empty($result['success'])) ? 200 : 400;
            return new WP_REST_Response($result, $code);
        },
        'permission_callback' => 'gv_permission_callback',
    ));
});

// (Opcional) Endpoint genérico para SQL unlink directo
add_action('rest_api_init', function () {
    register_rest_route('gv/v1', '/relations/sql-unlink', array(
        'methods'  => 'POST',
        'permission_callback' => 'gv_permission_callback',
        'callback' => function (WP_REST_Request $r) {
            $p = gv_read_payload($r);

            // Puedes pasar 'rel' simbólico o 'rel_id'
            $rel_id = isset($p['rel_id']) ? (int)$p['rel_id'] : 0;
            if (!$rel_id && !empty($p['rel'])) {
                $map = array(
                    'evento_ponente'  => defined('GV_JE_RELATION_ID') ? (int)GV_JE_RELATION_ID : 0,
                    'empresa_ponente' => defined('GV_JE_RELATION_EMPRESA_PONENTE_ID') ? (int)GV_JE_RELATION_EMPRESA_PONENTE_ID : 0,
                );
                $key = strtolower(trim((string)$p['rel']));
                $rel_id = $map[$key] ?? 0;
            }

            $parent_id    = isset($p['parent_id']) ? (int)$p['parent_id'] : 0;
            $child_id     = isset($p['child_id'])  ? (int)$p['child_id']  : 0;
            $child_ids_in = $p['child_ids'] ?? array();
            $child_ids    = is_array($child_ids_in) ? array_map('intval', $child_ids_in) : array();
            if ($child_id) $child_ids[] = $child_id;

            $all_children = !empty($p['all_children']);

            $res = gv_sql_unlink_relations($rel_id, $parent_id, $child_ids, $all_children);
            $code = !empty($res['success']) ? 200 : 400;
            return new WP_REST_Response($res, $code);
        },
    ));
});

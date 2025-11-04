<?php
require_once 'config.php';

/**
 * 🔍 Buscar contacto por email en módulo Contacts
 */
if (!function_exists('searchContactByEmail')) {
    function searchContactByEmail($email) {
        $access_token = getAccessToken();
        $url = "https://www.zohoapis.eu/crm/v2/Contacts/search?criteria=(Email:equals:" . urlencode($email) . ")";

        $headers = [
            "Authorization: Zoho-oauthtoken $access_token",
            "Content-Type: application/json"
        ];

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            error_log("❌ Error buscando contacto: " . $err);
            return false;
        }

        $data = json_decode($response, true);
        error_log("🔍 Resultado búsqueda contacto: " . print_r($data, true));
        return $data;
    }
}

/**
 * ➕ Crear contacto en módulo Contacts
 */
if (!function_exists('createContactZoho')) {
    function createContactZoho($contactData) {
        $access_token = getAccessToken();
        $url = "https://www.zohoapis.eu/crm/v2/Contacts";

        $headers = [
            "Authorization: Zoho-oauthtoken $access_token",
            "Content-Type: application/json"
        ];

        $payload = ["data" => [$contactData]];

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            error_log("❌ Error creando contacto: " . $err);
            return false;
        }

        $data = json_decode($response, true);
        error_log("✅ Contacto creado: " . print_r($data, true));
        return $data;
    }
}

/**
 * 🔗 Crear relación Contacto ↔ Evento en módulo Contactos_vs_Eventos
 */
if (!function_exists('createContactEventRelation')) {
    function createContactEventRelation($contactId, $eventId, $status = "Inscrito") {
        $access_token = getAccessToken();
        $url = 'https://www.zohoapis.eu/crm/v2/Contactos_vs_Eventos';

        $headers = [
            "Authorization: Zoho-oauthtoken $access_token",
            "Content-Type: application/json"
        ];

        $payload = [
            "data" => [[
                "Contactos" => $contactId,
                "Eventos"   => $eventId,
                "Status"    => $status
            ]]
        ];

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            error_log("❌ Error creando relación Contacto-Evento: " . $err);
            return false;
        }

        $data = json_decode($response, true);
        error_log("✅ Relación creada Contacto-Evento: " . print_r($data, true));
        return $data;
    }
}
?>

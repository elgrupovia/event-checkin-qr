<?php
// ============================================
// 🔹 ZOHO CONTACT HANDLER (plugin version)
// ============================================
// Este archivo se encarga de buscar, crear o actualizar contactos
// y vincularlos con eventos dentro del Zoho CRM desde el plugin
// "event-checkin-qr-plugin".

require_once __DIR__ . '/config.php';

// --------------------------------------------
// Helper para llamadas a la API de Zoho
// --------------------------------------------
if (!function_exists('zohoApiCall')) {
    function zohoApiCall($method, $endpoint, $data = null) {
        $access_token = getAccessToken();
        $url = 'https://www.zohoapis.eu/crm/v2/' . $endpoint; // 🔸 usa el dominio .eu

        $headers = [
            "Authorization: Zoho-oauthtoken $access_token",
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("❌ cURL Error: $err");
            return false;
        }

        $decoded = json_decode($response, true);
        error_log("📡 Zoho API [$endpoint]: " . print_r($decoded, true));
        return $decoded;
    }
}

// --------------------------------------------
// Buscar contacto por email
// --------------------------------------------
if (!function_exists('searchContactByEmail')) {
    function searchContactByEmail($email) {
        if (empty($email)) return false;

        $endpoint = "Contacts/search?criteria=(Email:equals:" . urlencode($email) . ")";
        $response = zohoApiCall("GET", $endpoint);

        if (!empty($response['data'][0])) {
            $contact = $response['data'][0];
            error_log("✅ Contacto encontrado: " . $contact['Full_Name'] . " (ID: " . $contact['id'] . ")");
            return $contact;
        }

        error_log("ℹ️ No se encontró contacto con el email: $email");
        return false;
    }
}

// --------------------------------------------
// Crear nuevo contacto
// --------------------------------------------
if (!function_exists('createContact')) {
    function createContact($data) {
        if (empty($data['Email'])) {
            error_log("⚠️ Falta el campo 'Email' al crear contacto.");
            return false;
        }

        $payload = [
            "data" => [[
                "First_Name" => $data['First_Name'] ?? '',
                "Last_Name"  => $data['Last_Name'] ?? 'Sin Apellido',
                "Email"      => $data['Email'],
                "Phone"      => $data['Phone'] ?? '',
                "Title"      => $data['Title'] ?? '',
                "Account_Name" => isset($data['Account_Name']) ? ["name" => $data['Account_Name']] : null
            ]]
        ];

        $response = zohoApiCall("POST", "Contacts", $payload);

        if (!empty($response['data'][0]['details']['id'])) {
            $id = $response['data'][0]['details']['id'];
            error_log("✅ Contacto creado con éxito: {$data['First_Name']} (ID: $id)");
            return $id;
        }

        error_log("❌ Error al crear contacto: " . print_r($response, true));
        return false;
    }
}

// --------------------------------------------
// Actualizar contacto por ID de Zoho
// --------------------------------------------
if (!function_exists('updateContactByZohoID')) {
    function updateContactByZohoID($contactId, $data) {
        if (!$contactId) {
            error_log("⚠️ updateContactByZohoID: ID no proporcionado.");
            return false;
        }

        $payload = ["data" => [$data]];
        $response = zohoApiCall("PUT", "Contacts/$contactId", $payload);

        if (!empty($response['data'][0]['code']) && $response['data'][0]['code'] === "SUCCESS") {
            error_log("✅ Contacto actualizado correctamente (ID: $contactId)");
            return true;
        }

        error_log("❌ Error al actualizar contacto: " . print_r($response, true));
        return false;
    }
}

// --------------------------------------------
// Crear un Lead (opcional)
// --------------------------------------------
if (!function_exists('createLead')) {
    function createLead($data) {
        $payload = ["data" => [$data]];
        $response = zohoApiCall("POST", "Leads", $payload);

        if (!empty($response['data'][0]['details']['id'])) {
            $id = $response['data'][0]['details']['id'];
            error_log("✅ Lead creado (ID: $id)");
            return $id;
        }

        error_log("❌ Error al crear lead: " . print_r($response, true));
        return false;
    }
}
?>

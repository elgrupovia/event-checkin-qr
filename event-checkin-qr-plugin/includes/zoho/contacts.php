<?php
require_once 'config.php';

function getContacts($criteria = null) {
    $access_token = getAccessToken();
    $url = 'https://www.zohoapis.com/crm/v2/Contactos_vs_Eventos';
    if ($criteria) {
        $url .= '/search?criteria=' . urlencode($criteria);
    }

    $headers = array(
        "Authorization: Zoho-oauthtoken $access_token"
    );

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        die("cURL Error #:" . $err);
    } else {
        return json_decode($response, true);
    }
}

function createContact($data) {
    $access_token = getAccessToken();
    $url = 'https://www.zohoapis.com/crm/v2/Contactos_vs_Eventos';
    $headers = array(
        "Authorization: Zoho-oauthtoken $access_token",
        "Content-Type: application/json"
    );

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        die("cURL Error #:" . $err);
    } else {
        return json_decode($response, true);
    }
}


function searchContactByEmail($email) {
    $access_token = getAccessToken();
    $url = "https://www.zohoapis.com/crm/v2/Contacts/search?criteria=(Email:equals:" . urlencode($email) . ")";

    $headers = array(
        "Authorization: Zoho-oauthtoken $access_token",
        "Content-Type: application/json"
    );

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        error_log("cURL Error #:" . $err);
        return false;
    } else {
        $response_data = json_decode($response, true);
        return $response_data; // Contiene la respuesta de la búsqueda
    }
}


function updateuserByZohoID($id_zoho_user_web, $data) {
    $access_token = getAccessToken();
    $url = "https://www.zohoapis.com/crm/v2/Contacts/" . $id_zoho_user_web;
    error_log('Función Actualizar USUARIO disparada a la URL: ' . $url);
    error_log('Función Actualizar USUARIO disparada a la ID_USER_ZOHO_EN_WEB: ' .$id_zoho_user_web);
    $headers = array(
        "Authorization: Zoho-oauthtoken $access_token",
        "Content-Type: application/json"
    );

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(array("data" => [$data])));

    $response = curl_exec($curl);
    error_log(print_r($response,true));
    $err = curl_error($curl);
    curl_close($curl);
    
    if ($err) {
        error_log("cURL Error #:" . $err);
        return false;
    } else {
        $response_data = json_decode($response, true);
        return $response_data; // Contiene la respuesta de la actualización
    }
}


function createLead($data) {
    $access_token = getAccessToken();
    $url = 'https://www.zohoapis.com/crm/v2/Leads';
    $headers = array(
        "Authorization: Zoho-oauthtoken $access_token",
        "Content-Type: application/json"
    );

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        error_log("cURL Error #:" . $err);
        return false;
    } else {
        return json_decode($response, true);
    }
}


// Ejemplo de uso
//$criteria = "(Contactos:starts_with:David)";
//$response = getContacts($criteria);
//echo '<pre>'; print_r($response); echo '</pre>';

/*$new_contact = array(
    "data" => [
        [
            "First_Name" => "John",
            "Last_Name" => "Macarrita",
            "Email" => "john.doe@example.com",
            "Empresa" => 1442205000010833013,
            "Contactos" => 1442205000002177141,
            "Status" => "Seguir",
            "Eventos" => 1442205000105515156
        ]
    ]
);
$response = createContact($new_contact);
echo '<pre>'; print_r($response); echo '</pre>';

*/
?>
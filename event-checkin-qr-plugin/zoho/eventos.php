<?php


require_once 'config.php';

function searchEvents($criteria) {
    $access_token = getAccessToken();
    $url = "https://www.zohoapis.com/crm/v2/Eventos/search?criteria=" . urlencode($criteria);

    error_log('URL generada: ' . $url); // Añadir log para verificar la URL

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
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    error_log('HTTP Code: ' . $http_code); // Añadir log para el código de respuesta HTTP

    if ($err) {
        error_log("cURL Error #:" . $err);
        return false;
    } else {
        error_log('Raw Response: ' . $response); // Añadir log para la respuesta cruda
        $response_data = json_decode($response, true);
        error_log('Response data: ' . print_r($response_data, true)); // Añadir log para la respuesta decodificada

        if (isset($response_data['data'])) {
            return $response_data['data']; // Devuelve la lista de eventos encontrados
        } else {
            error_log('Error en la respuesta de Zoho: ' . print_r($response_data, true));
            return false;
        }
    }
}

?>

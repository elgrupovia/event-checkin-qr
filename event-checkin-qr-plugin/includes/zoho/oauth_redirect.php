<?php
$client_id = '1000.KB3EVPYRNDSJ2REYPBDAOYH6L9F71N';
$client_secret = '79639bdd17caaaff397ed4714e1b1812fbfe95dda8';
$redirect_uri = 'https://www.grupovia.net/oauth_redirect.php';

if (isset($_GET['code'])) {
    $authorization_code = sanitize_text_field($_GET['code']);
    $access_token_response = exchange_authorization_code_for_access_token($authorization_code, $client_id, $client_secret, $redirect_uri);
    
    if (isset($access_token_response['access_token'])) {
        $access_token = $access_token_response['access_token'];
        $refresh_token = $access_token_response['refresh_token'];
        $token_type = $access_token_response['token_type'];
        $expires_in = $access_token_response['expires_in'];
        
        // Guardar el token de acceso en la base de datos
        $db_host = 'localhost';
        $db_user = 'u671245024_wuYWm';
        $db_password = 'qn4Gm56f6a';
        $db_name = 'u671245024_XdgU7';

        // Crear conexión
        $mysqli = new mysqli($db_host, $db_user, $db_password, $db_name);

        // Comprobar la conexión
        if ($mysqli->connect_error) {
            die("Error de conexión: " . $mysqli->connect_error);
        }

        // Guardar el token en la base de datos con la fecha de creación
        $stmt = $mysqli->prepare("INSERT INTO oauth_tokens (access_token, refresh_token, token_type, expires_in, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param('ssss', $access_token, $refresh_token, $token_type, $expires_in);
        if ($stmt->execute()) {
            echo "Token de acceso guardado correctamente.";
        } else {
            echo "Error al guardar el token de acceso: " . $stmt->error;
        }

        // Cerrar conexión
        $stmt->close();
        $mysqli->close();
    } else {
        echo "Error al obtener el token de acceso: " . json_encode($access_token_response);
    }
} else {
    echo "No se proporcionó código de autorización.";
}

function exchange_authorization_code_for_access_token($code, $client_id, $client_secret, $redirect_uri) {
    $url = "https://accounts.zoho.com/oauth/v2/token";
    $data = array(
        'grant_type' => 'authorization_code',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'code' => $code
    );

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        return array('error' => $err);
    } else {
        return json_decode($response, true);
    }
}
?>
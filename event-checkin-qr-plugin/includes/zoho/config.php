<?php

// Configuración de la base de datos
$db_host = 'localhost';
$db_user = 'u671245024_wuYWm';
$db_password = 'qn4Gm56f6a';
$db_name = 'u671245024_XdgU7';

// Credenciales de Zoho
$client_id = '1000.KB3EVPYRNDSJ2REYPBDAOYH6L9F71N';
$client_secret = '79639bdd17caaaff397ed4714e1b1812fbfe95dda8';
$redirect_uri = 'https://www.grupovia.net/oauth_redirect.php';

function getDatabaseConnection() {
    global $db_host, $db_user, $db_password, $db_name;
    $mysqli = new mysqli($db_host, $db_user, $db_password, $db_name);

    if ($mysqli->connect_error) {
        die("Error de conexión: " . $mysqli->connect_error);
    }

    return $mysqli;
}

function getAccessToken() {
    $mysqli = getDatabaseConnection();
    $query = "SELECT access_token, refresh_token, expires_in, created_at FROM oauth_tokens ORDER BY created_at DESC LIMIT 1";
    $result = $mysqli->query($query);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $expires_in = intval($row['expires_in']);
        $created_at = strtotime($row['created_at']);

        // Verificar si el token ha expirado
        if ((time() - $created_at) > $expires_in) {
            // El token ha expirado, renovarlo
            $new_token_data = refreshAccessToken($row['refresh_token']);
            return $new_token_data['access_token'];
        } else {
            return $row['access_token'];
        }
    } else {
        die("No se encontró un token de acceso válido.");
    }
}

function refreshAccessToken($refresh_token) {
    global $client_id, $client_secret, $redirect_uri;

    $url = "https://accounts.zoho.com/oauth/v2/token";
    $data = array(
        'refresh_token' => $refresh_token,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'refresh_token'
    );

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        die("cURL Error #:" . $err);
    } else {
        $response_data = json_decode($response, true);

        if (isset($response_data['access_token'])) {
            // Guardar el nuevo token en la base de datos
            $mysqli = getDatabaseConnection();
            $stmt = $mysqli->prepare("INSERT INTO oauth_tokens (access_token, refresh_token, token_type, expires_in, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param('ssss', $response_data['access_token'], $refresh_token, $response_data['token_type'], $response_data['expires_in']);
            if (!$stmt->execute()) {
                die("Error al guardar el token de acceso: " . $stmt->error);
            }
            $stmt->close();
            $mysqli->close();

            return $response_data;
        } else {
            die("Error al renovar el token de acceso: " . json_encode($response_data));
        }
    }
}

?>

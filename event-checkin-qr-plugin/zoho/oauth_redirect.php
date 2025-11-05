<?php
/**
 * oauth_redirect.php
 * Intercambia el código de autorización por tokens y los guarda en la base de datos.
 * Compatible con cuentas Zoho .com o .eu
 */

$client_id = '1000.KB3EVPYRNDSJ2REYPBDAOYH6L9F71N';
$client_secret = '79639bdd17caaaff397ed4714e1b1812fbfe95dda8';
$redirect_uri = 'https://www.grupovia.net/oauth_redirect.php';

// 🌍 Cambia a 'eu' si tu cuenta Zoho es europea
$zoho_domain = 'com';

// Verifica que venga el "code"
if (isset($_GET['code'])) {
    $authorization_code = htmlspecialchars($_GET['code']); // reemplaza sanitize_text_field

    // Intercambiar código por tokens
    $token_response = exchange_authorization_code_for_access_token($authorization_code, $client_id, $client_secret, $redirect_uri, $zoho_domain);

    if (isset($token_response['access_token'])) {
        $access_token  = $token_response['access_token'];
        $refresh_token = $token_response['refresh_token'] ?? null;
        $token_type    = $token_response['token_type'] ?? 'Zoho-oauthtoken';
        $expires_in    = $token_response['expires_in'] ?? 3600;

        // Guardar en base de datos
        $db_host = 'localhost';
        $db_user = 'u671245024_wuYWm';
        $db_password = 'qn4Gm56f6a';
        $db_name = 'u671245024_XdgU7';

        $mysqli = new mysqli($db_host, $db_user, $db_password, $db_name);
        if ($mysqli->connect_error) {
            die("❌ Error de conexión a la BD: " . $mysqli->connect_error);
        }

        $stmt = $mysqli->prepare("INSERT INTO oauth_tokens (access_token, refresh_token, token_type, expires_in, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param('ssss', $access_token, $refresh_token, $token_type, $expires_in);
        $success = $stmt->execute();

        if ($success) {
            echo "<h2>✅ Token de acceso de Zoho guardado correctamente.</h2>";
            echo "<p>Puedes cerrar esta pestaña y volver al panel de WordPress.</p>";
        } else {
            echo "<h2>⚠️ Error al guardar el token de acceso.</h2><p>" . htmlspecialchars($stmt->error) . "</p>";
        }

        $stmt->close();
        $mysqli->close();

        // Log para depuración
        error_log("✅ Nuevo token Zoho guardado. Expira en {$expires_in}s");
    } else {
        echo "<h2>❌ Error al obtener el token de acceso de Zoho.</h2>";
        echo "<pre>" . json_encode($token_response, JSON_PRETTY_PRINT) . "</pre>";
        error_log("❌ Error al obtener el token de Zoho: " . json_encode($token_response));
    }

} else {
    echo "<h2>⚠️ No se proporcionó código de autorización (GET['code']).</h2>";
}

/**
 * Intercambia el authorization_code por un access_token + refresh_token
 */
function exchange_authorization_code_for_access_token($code, $client_id, $client_secret, $redirect_uri, $zoho_domain = 'com') {
    $url = "https://accounts.zoho.$zoho_domain/oauth/v2/token";
    $data = [
        'grant_type' => 'authorization_code',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'code' => $code
    ];

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        error_log("❌ Error cURL al obtener token Zoho: " . $err);
        return ['error' => $err];
    }

    return json_decode($response, true);
}
?>

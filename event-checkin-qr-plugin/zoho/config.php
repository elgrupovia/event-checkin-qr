<?php
/**
 * Configuración Zoho + Manejo automático de tokens
 * Compatible con Zoho CRM EU o COM (ajusta el dominio según tu cuenta)
 */

// 🔧 CONFIGURACIÓN BASE DE DATOS
$db_host = 'localhost';
$db_user = 'u671245024_wuYWm';
$db_password = 'qn4Gm56f6a';
$db_name = 'u671245024_XdgU7';

// 🔑 CREDENCIALES ZOHO (ajusta dominio si tu cuenta es EU)
$client_id = '1000.KB3EVPYRNDSJ2REYPBDAOYH6L9F71N';
$client_secret = '79639bdd17caaaff397ed4714e1b1812fbfe95dda8';
$redirect_uri = 'https://www.grupovia.net/oauth_redirect.php';

// 🌍 DOMINIO ZOHO — cambia a “.eu” si tu cuenta está en Europa
$zoho_domain = 'com'; // o 'eu' si tu cuenta es europea


// ------------------ FUNCIONES ------------------ //

if (!function_exists('getDatabaseConnection')) {
    function getDatabaseConnection() {
        global $db_host, $db_user, $db_password, $db_name;
        $mysqli = new mysqli($db_host, $db_user, $db_password, $db_name);
        if ($mysqli->connect_error) {
            error_log("❌ Error de conexión BD (Zoho config.php): " . $mysqli->connect_error);
            throw new Exception("Error de conexión: " . $mysqli->connect_error);
        }
        return $mysqli;
    }
}

/**
 * Obtener token actual o renovar automáticamente si expiró
 */
if (!function_exists('getAccessToken')) {
    function getAccessToken() {
        $mysqli = getDatabaseConnection();
        $query = "SELECT access_token, refresh_token, expires_in, created_at FROM oauth_tokens ORDER BY created_at DESC LIMIT 1";
        $result = $mysqli->query($query);

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $expires_in = intval($row['expires_in']);
            $created_at = strtotime($row['created_at']);

            // Verificar expiración
            if ((time() - $created_at) > $expires_in - 60) { // margen de 1 min
                error_log("🔄 Token Zoho expirado, renovando...");
                $new = refreshAccessToken($row['refresh_token']);
                return $new['access_token'] ?? null;
            } else {
                return $row['access_token'];
            }
        } else {
            error_log("⚠️ No se encontró token válido en BD.");
            throw new Exception("No se encontró un token de acceso válido en la base de datos.");
        }
    }
}

/**
 * Renovar token Zoho CRM con refresh_token
 */
if (!function_exists('refreshAccessToken')) {
    function refreshAccessToken($refresh_token) {
        global $client_id, $client_secret, $redirect_uri, $zoho_domain;

        $url = "https://accounts.zoho.$zoho_domain/oauth/v2/token";
        $data = [
            'refresh_token' => $refresh_token,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $redirect_uri,
            'grant_type' => 'refresh_token'
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
            error_log("❌ Error al renovar token Zoho: " . $err);
            throw new Exception("Error al renovar token: $err");
        }

        $json = json_decode($response, true);
        if (isset($json['access_token'])) {
            // Guardar en BD
            $mysqli = getDatabaseConnection();
            $stmt = $mysqli->prepare("
                INSERT INTO oauth_tokens (access_token, refresh_token, token_type, expires_in, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param('ssss', $json['access_token'], $refresh_token, $json['token_type'], $json['expires_in']);
            $stmt->execute();

            if ($stmt->error) {
                error_log("⚠️ Error al guardar nuevo token: " . $stmt->error);
            } else {
                error_log("✅ Token Zoho renovado correctamente.");
            }

            $stmt->close();
            $mysqli->close();

            return $json;
        } else {
            error_log("⚠️ Falló la renovación del token: " . json_encode($json));
            throw new Exception("Respuesta inválida de Zoho: " . json_encode($json));
        }
    }
}
?>

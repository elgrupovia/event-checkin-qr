<?php
$client_id = '1000.KB3EVPYRNDSJ2REYPBDAOYH6L9F71N';
$redirect_uri = 'https://www.grupovia.net/oauth_redirect.php';
$scope = 'ZohoCRM.modules.ALL'; // Ajusta el alcance según tus necesidades

$authorization_url = "https://accounts.zoho.com/oauth/v2/auth?response_type=code&client_id={$client_id}&redirect_uri={$redirect_uri}&scope={$scope}&access_type=offline";

header('Location: ' . $authorization_url);
exit;

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
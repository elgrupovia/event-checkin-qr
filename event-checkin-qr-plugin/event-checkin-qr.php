<?php
/**
 * Plugin Name: Event Check-In QR
 * Plugin URI:  https://github.com/elgrupovia/event-checkin-qr.git
 * Description: Genera códigos QR y PDFs para check-in en eventos.
 * Version:     1.0.0
 * Author:      Grupovia
 * Author URI:  https://github.com/elgrupovia
 * License:     GPL2
 */

// Seguridad: evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// 🔹 Registrar en el log que el plugin principal se está cargando
error_log("✅ Plugin principal Event Check-In QR cargado.");

// 🔹 Incluir el archivo de funciones del plugin
$functions_path = plugin_dir_path(__FILE__) . 'includes/functions.php';

if (file_exists($functions_path)) {
    require_once $functions_path;
    error_log("✅ functions.php incluido correctamente desde: " . $functions_path);
} else {
    error_log("⚠️ No se encontró el archivo functions.php en: " . $functions_path);
}

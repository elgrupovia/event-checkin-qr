<?php
// Cargar TCPDF
require_once __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php';
require_once __DIR__ . '/vendor/tecnickcom/tcpdf/tools/tcpdf_addfont.php';

// Cambia esta ruta si tus TTF están en otro sitio
$fonts_dir = __DIR__ . '/vendor/tecnickcom/tcpdf/fonts/';

AddFont($fonts_dir . 'gotham-Bold.ttf', '', 'TrueTypeUnicode', true);
AddFont($fonts_dir . 'gotham-Book.ttf', '', 'TrueTypeUnicode', true);

echo '✅ Fuentes Gotham generadas correctamente';

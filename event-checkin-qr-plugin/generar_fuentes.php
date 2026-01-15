<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Cargar TCPDF
require_once __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php';
require_once __DIR__ . '/vendor/tecnickcom/tcpdf/tools/tcpdf_addfont.php';

// Ruta a las fuentes
$fonts_dir = __DIR__ . '/vendor/tecnickcom/tcpdf/fonts/';

// Generar fuentes TCPDF desde TTF
AddFont($fonts_dir . 'gotham-bold.ttf', '', 'TrueTypeUnicode', true);
AddFont($fonts_dir . 'gotham-book.ttf', '', 'TrueTypeUnicode', true);

echo '✅ Gotham convertida correctamente';

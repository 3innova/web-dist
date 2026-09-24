<?php
/**
 * Punto de entrada del build (plantilla nginx de HestiaCP tipo WordPress:
 * `index index.php index.html; try_files $uri $uri/ /index.php?$args;`).
 *
 * - Ruta exactamente "/"  -> sirve index.html (home) con 200.
 * - Cualquier otra ruta   -> sirve 404.html con status 404 (URL inexistente).
 *
 * Vive en la raíz de public_html (junto a index.html y 404.html), copiado del
 * build por Astro (site/public -> dist) igual que el resto de la web.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/') {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
} else {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/404.html');
}

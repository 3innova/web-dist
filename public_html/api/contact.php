<?php
/**
 * /api/contact.php — formulario de contacto de 3innova.io.
 *
 * Sustituye al antiguo contact-api (Node/nodemailer) por PHP 8.x sin
 * frameworks. PHPMailer está vendorizado en api/vendor/ (no se ejecuta
 * Composer en el servidor).
 *
 * Comportamiento (idéntico al contact-api que sustituye):
 *   - POST JSON o form-urlencoded (fallback sin JS) en /api/contact.php
 *   - validación en servidor, honeypot, rate limit por IP con fichero
 *   - límite de tamaño (413), checkbox RGPD obligatorio, selector de sector
 *   - SIEMPRE persiste el mensaje en private/contact-messages.jsonl
 *   - envía por SMTP de Hestia solo si no está en dry-run y hay config
 *   - si el envío falla, el mensaje queda persistido, se registra el error y
 *     el visitante ve confirmación normal (nunca se revela el fallo)
 *   - respuesta JSON; formulario con fetch sin recargar (+ fallback sin JS)
 *
 * Configuración: private/contact-config.php (fuera de public_html) o
 * variables de entorno. Ver api/README.md.
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require __DIR__ . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// Configuración
// ---------------------------------------------------------------------------
$config = [];
// En prod: public_html/api -> ../.. -> web/<dominio>/private/contact-config.php
$configFile = getenv('CONTACT_CONFIG_FILE') ?: dirname(__DIR__, 2) . '/private/contact-config.php';
if (is_file($configFile)) {
    $loaded = require $configFile;
    if (is_array($loaded)) {
        $config = $loaded;
    }
}

const DNS = [
    'smtp' => [
        'host'    => 'localhost',
        'port'    => 587,
        'user'    => '',
        'pass'    => '',
        'auth'    => true,
        'secure'  => 'tls',
        'timeout' => 15,
    ],
    'mail_from'         => '',
    'mail_to'           => '',
    'mail_name'         => 'Web 3innova.io',
    'dry_run'           => true,
    'rate_limit_max'    => 5,
    'rate_window_sec'   => 60,
    'max_body_bytes'    => 16384,
    'messages_file'     => '',
    'rate_file'         => '',
];
foreach (DNS as $k => $v) {
    if (!array_key_exists($k, $config)) {
        $config[$k] = $v;
    }
}

// Variables de entorno (dev vía docker-compose) complementan/sobrescriben.
$envMap = [
    'SMTP_HOST' => ['smtp', 'host'],
    'SMTP_PORT' => ['smtp', 'port'],
    'SMTP_USER' => ['smtp', 'user'],
    'SMTP_PASS' => ['smtp', 'pass'],
    'SMTP_TLS'  => ['smtp', 'secure'],
    'MAIL_FROM' => 'mail_from',
    'MAIL_TO'   => 'mail_to',
    'SMTP_DRY_RUN' => 'dry_run',
];
foreach ($envMap as $env => $key) {
    $val = getenv($env);
    if ($val === false || $val === '') {
        continue;
    }
    if (is_array($key)) {
        $config[$key[0]][$key[1]] = $val;
    } else {
        $config[$key] = $val;
    }
}
$config['dry_run'] = filter_var($config['dry_run'], FILTER_VALIDATE_BOOL);

// Rutas por defecto de persistencia: private/ junto al public_html (Hestia).
if ($config['messages_file'] === '') {
    $config['messages_file'] = dirname(__DIR__, 2) . '/private/contact-messages.jsonl';
}
if ($config['rate_file'] === '') {
    $config['rate_file'] = dirname(__DIR__, 2) . '/private/rate-limit.json';
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // Fallback sin JS: el navegador hace POST normal (form-urlencoded) y
    // espera HTML; para él es un éxito aunque por debajo se haya persistido.
    if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'text/html')
        && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        $msg = $data['ok']
            ? 'Gracias, tu mensaje se ha enviado correctamente. Te responderemos lo antes posible.'
            : ($data['errors'] ? implode(' ', $data['errors']) : ($data['error'] ?? 'Error.'));
        echo '<!doctype html><meta charset="utf-8"><title>Mensaje enviado</title>'
            . '<body style="font:16px/1.6 system-ui;max-width:36em;margin:4em auto">'
            . '<p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="/">Volver a 3innova.io</a></p></body>';
        exit;
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clientIp(): string
{
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '') {
        $parts = explode(',', $fwd);
        $ip = trim($parts[0]);
        if ($ip !== '') {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '?';
}

function rateLimited(array $config, string $ip): bool
{
    $now = time();
    $max = (int) $config['rate_limit_max'];
    $win = (int) $config['rate_window_sec'];
    $file = $config['rate_file'];
    $hits = [];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        $hits = is_array($decoded) ? $decoded : [];
    }
    // Limpieza de ventanas vencidas
    foreach ($hits as $k => $rec) {
        if ($now - (int) ($rec['t'] ?? 0) > $win) {
            unset($hits[$k]);
        }
    }
    $rec = $hits[$ip] ?? ['n' => 0, 't' => $now];
    $rec['n'] = (int) $rec['n'] + 1;
    $hits[$ip] = $rec;

    $fp = @fopen($file, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($hits, JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return $rec['n'] > $max;
}

function persist(array $config, array $record): void
{
    $file = $config['messages_file'];
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $fp = @fopen($file, 'a');
    if ($fp) {
        flock($fp, LOCK_EX);
        fwrite($fp, $line);
        flock($fp, LOCK_UN);
        fclose($fp);
    } else {
        error_log('[contact] No se pudo persistir en ' . $file);
    }
}

function sendMail(array $config, array $c): string
{
    if ($config['dry_run']) {
        return 'dry-run';
    }
    if (empty($config['smtp']['host']) || empty($config['mail_from']) || empty($config['mail_to'])) {
        error_log('[contact] SMTP no configurado; mensaje persistido de ' . $c['email']);
        return 'not-configured';
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $config['smtp']['host'];
        $mail->Port       = (int) $config['smtp']['port'];
        $mail->SMTPAuth   = (bool) ($config['smtp']['auth'] ?? true);
        $mail->Username   = $config['smtp']['user'];
        $mail->Password   = $config['smtp']['pass'];
        $mail->SMTPSecure = $config['smtp']['secure'] === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout    = (int) ($config['smtp']['timeout'] ?? 15);
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($config['mail_from'], $config['mail_name'] ?? 'Web 3innova.io');
        $mail->addAddress($config['mail_to']);
        $mail->addReplyTo($c['email'], $c['name']);

        $mail->Subject = 'Contacto web 3innova — ' . $c['name'];
        $body = [
            'Nuevo mensaje desde el formulario de 3innova.io',
            '',
            'Nombre: ' . $c['name'],
            'Email: ' . $c['email'],
            'Teléfono: ' . ($c['phone'] !== '' ? $c['phone'] : '—'),
            'Sector: ' . ($c['sector'] !== '' ? $c['sector'] : '—'),
            '',
            $c['message'],
        ];
        $mail->Body = implode("\n", $body);
        $mail->isHTML(false);
        $mail->send();
        return 'sent';
    } catch (PHPMailerException | Exception $e) {
        error_log('[contact] Error SMTP: ' . $e->getMessage() . '; mensaje persistido de ' . $c['email']);
        return 'error';
    }
}

// ---------------------------------------------------------------------------
// Endpoint
// ---------------------------------------------------------------------------
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

// Límite de tamaño antes de leer el cuerpo
$clen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($clen > (int) $config['max_body_bytes']) {
    respond(['ok' => false, 'error' => 'Petición demasiado grande.'], 413);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$bodyRaw = file_get_contents('php://input');
if (strlen($bodyRaw) > (int) $config['max_body_bytes']) {
    respond(['ok' => false, 'error' => 'Petición demasiado grande.'], 413);
}

if (str_contains($contentType, 'application/x-www-form-urlencoded') || str_contains($contentType, 'multipart/form-data')) {
    // Fallback sin JS: $_POST ya poblado por PHP.
    $body = $_POST;
} else {
    $body = json_decode($bodyRaw, true);
    if (!is_array($body)) {
        respond(['ok' => false, 'error' => 'JSON inválido.'], 400);
    }
}

$ip = clientIp();
if (rateLimited($config, $ip)) {
    respond(['ok' => false, 'error' => 'Demasiadas peticiones. Inténtalo de nuevo en un minuto.'], 429);
}

// Honeypot: campo oculto relleno => responder OK sin persistir ni enviar.
if (!empty($body['website'])) {
    respond(['ok' => true]);
}

// Validación
$name    = trim((string) ($body['name'] ?? ''));
$email   = trim((string) ($body['email'] ?? ''));
$phone   = trim((string) ($body['phone'] ?? ''));
$message = trim((string) ($body['message'] ?? ''));
$sector  = trim(substr((string) ($body['sector'] ?? ''), 0, 80));
$privacy = isset($body['privacy']) && in_array($body['privacy'], [true, 'true', '1', 'on'], true);

$errors = [];
if (mb_strlen($name) < 2) {
    $errors['name'] = 'Indica tu nombre.';
}
if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $email)) {
    $errors['email'] = 'Indica un email válido.';
}
if (mb_strlen($message) < 10) {
    $errors['message'] = 'El mensaje debe tener al menos 10 caracteres.';
}
if (!$privacy) {
    $errors['privacy'] = 'Debes aceptar la política de privacidad.';
}
if ($errors) {
    respond(['ok' => false, 'errors' => $errors], 400);
}

$outcome = sendMail($config, [
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'sector' => $sector,
    'message' => $message,
]);

persist($config, [
    'ts' => gmdate('c'),
    'ip' => $ip,
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'sector' => $sector,
    'message' => $message,
    'smtp' => $outcome,
]);

error_log('[contact] persistido (' . $outcome . ') desde ' . $ip . ': ' . $email);
respond(['ok' => true], 200);
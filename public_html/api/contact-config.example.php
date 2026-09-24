<?php
/**
 * contact-config.example.php — plantilla de configuración del formulario.
 *
 * En producción este fichero NO se versiona ni se despliega: se instala a mano
 * en /home/<usuario>/web/3innova.io/private/contact-config.php (fuera de
 * public_html), con los secretos reales. En desarrollo basta con las variables
 * de entorno del docker-compose (SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS,
 * MAIL_FROM, MAIL_TO, SMTP_DRY_RUN) — ver api/README.md.
 *
 * Uso:  cp api/contact-config.example.php private/contact-config.php  (dev)
 *       cp api/contact-config.example.php …/private/contact-config.php (prod)
 * y editar. contact.php lo carga si existe; si no, cae a variables de entorno.
 */
return [
    // --- SMTP de Hestia ---
    // Buzón dedicado del dominio (contacto@3innova.io). host localhost y
    // puerto 587 con STARTTLS y autenticación (relay de HestiaCP).
    'smtp' => [
        'host'   => 'localhost',
        'port'   => 587,
        'user'   => 'contacto@3innova.io',
        'pass'   => 'PONER-CONTRASEÑA-BUZÓN',
        'auth'   => true,     // SMTPAuth
        'secure' => 'tls',    // PHPMailer::ENCRYPTION_STARTTLS
        'timeout'=> 15,
    ],

    // Remitente: SIEMPRE el buzón del dominio (SPF/DKIM de Hestia).
    // Destinatario: quien recibe los mensajes del formulario.
    'mail_from' => 'contacto@3innova.io',
    'mail_to'   => 'contacto@3innova.io',
    'mail_name' => 'Web 3innova.io', // nombre visible del remitente

    // true = NO envía correo (persiste igualmente). En prod: false.
    'dry_run' => true,

    // --- Protección ---
    'rate_limit_max'  => 5,    // envíos por IP en la ventana
    'rate_window_sec' => 60,
    'max_body_bytes'  => 16384,

    // --- Persistencia (NUNCA en public_html; private/ de Hestia) ---
    'messages_file' => dirname(__DIR__) . '/private/contact-messages.jsonl',
    'rate_file'     => dirname(__DIR__) . '/private/rate-limit.json',
];
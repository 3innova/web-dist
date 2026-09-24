# api/ — formulario de contacto (PHP)

`api/contact.php` sustituye al antiguo `contact-api` (Node/nodemailer). Es PHP 8.x
sin frameworks; **PHPMailer está vendorizado** en `api/vendor/` (se genera con
Composer en desarrollo y se versiona; en el servidor **no se ejecuta Composer**).

## Endpoint

- `POST /api/contact.php` — acepta **JSON** (`Content-Type: application/json`,
  usado por el JS del formulario) o **form-urlencoded** (fallback sin JS: el PHP
  responde una página HTML de confirmación cuando `Accept: text/html` y no hay
  `X-Requested-With`).
- Respuestas: `200 {"ok":true}` · `400 {"ok":false,"errors":{...}}` ·
  `405` (no POST) · `413` (cuerpo > `max_body_bytes`) · `429` (rate limit).

## Lógica (misma que el contact-api que sustituye)

1. Límite de tamaño antes de leer el cuerpo (`CONTENT_LENGTH` / `php://input`).
2. Rate limit por IP con fichero (`private/rate-limit.json`, `flock`): defecto
   5 envíos / 60 s.
3. Honeypot (campo `website` relleno → responder `ok` sin hacer nada).
4. Validación: nombre ≥ 2, email con patrón, mensaje ≥ 10 caracteres,
   **checkbox de privacidad obligatorio**, sector opcional.
5. **Persistencia SIEMPRE** en `private/contact-messages.jsonl` (append con
   `flock`) con marca temporal, IP y resultado SMTP.
6. Envío SMTP (PHPMailer): `localhost:587` STARTTLS + auth (panel de Hestia),
   `From` = buzón del dominio (`contacto@3innova.io`), `Reply-To` = email del
   usuario. Si falla o no hay SMTP configurado: se registra el error con
   `error_log` y el visitante **ve confirmación normal** (el mensaje ya está
   persistido; nunca se revela el fallo).
7. `dry_run` (por configuración): no envía correo, solo persiste.

## Configuración

El código resuelve la config así (en orden de precedencia):

1. Fichero PHP desde `CONTACT_CONFIG_FILE` o, por defecto,
   `dirname(__DIR__, 2) . '/private/contact-config.php'`
   (es decir `public_html/api/` → `web/<dominio>/private/contact-config.php`:
   **fuera de public_html**, nunca se sirve ni se despliega).
2. Variables de entorno (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`,
   `SMTP_TLS`, `MAIL_FROM`, `MAIL_TO`, `SMTP_DRY_RUN`) que complementan o
   sobrescriben los valores por defecto. En dev las inyecta docker-compose.

En el repo solo existe `contact-config.example.php` (plantilla). En producción
se crea a mano `private/contact-config.php`; en dev, basta el `.env` del compose.

## Persistencia

Los ficheros `contact-messages.jsonl` y `rate-limit.json` viven en `private/`
(sin versionar, `.gitignore`). En dev el contenedor `php` monta `./private` en
`/var/www/private` con permisos de escritura para `pitemp` (uid 1001, que es
`jlopez` en lab01).

## Composer (solo desarrollo, para regenerar vendor)

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 install
```

`composer.lock` está versionado; `api/vendor/` también (se despliega tal cual).
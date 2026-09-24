# 3innova/web-dist

Build de producción de https://3innova.io generado por `publish.sh`
(3innovalab01) a partir de https://github.com/3innova/web.

- `public_html/` — contenido a desplegar tal cual (dist Astro + `api/` con solo `contact.php` y `vendor/`).
- `DEPLOY_INFO` — commit de origen de 3innova/web y fecha del build.

En el servidor HestiaCP, `pull-deploy.sh` (en `private/`) descarga este repo
público con la API de GitHub y sincroniza `public_html/` con rsync, sin git
ni claves en el servidor.

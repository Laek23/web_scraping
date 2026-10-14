# Maxun API (Laravel 12)

## Requisitos

- PHP 8.2+ con `curl`, `dom`, `mbstring`, `openssl`, `pdo_mysql` y `zip`.
- Composer 2.
- MySQL 8+.

## Configuración local

1. Copia `.env.example` a `.env` si aún no existe.
2. Configura `DB_CONNECTION`, host, puerto, base de datos y credenciales en `.env`.
   El `.env` local no debe subirse al control de versiones.
3. Ejecuta:

```bash
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

La configuración local usa MySQL y la base `webscrap`. La API queda en
`http://127.0.0.1:8000/api`.

## Frontend y monitoreo

Inicia el frontend con `npm run dev` desde `Frontend`; Vite reenvía `/api` a
`http://127.0.0.1:8000`. En terminales separadas, inicia el planificador y el
worker de colas para activar tanto las ejecuciones periódicas como el monitoreo
de precios:

```bash
php artisan schedule:work
php artisan queue:work --tries=3
```

Los robots con monitorización activa se ejecutan según el intervalo guardado
(60 minutos por defecto; configurable entre 5 minutos y 7 días).
El job `UpdateProductPricesJob` consulta periódicamente los precios de ofertas
activas y agrega un registro al historial cuando detecta cambios.

## Rutas principales

- `POST /api/auth/register`, `POST /api/auth/login`, `POST /api/auth/demo`
- `GET /api/auth/me`, `POST /api/auth/logout`
- `GET|POST /api/robots`, `GET|PATCH|DELETE /api/robots/{id}`
- `POST /api/robots/{id}/run`
- `GET /api/executions`, `GET /api/executions/{id}`
- `GET|POST /api/tokens`, `DELETE /api/tokens/{id}`
- `POST /api/v1/extract-preview` (preview de Open Graph, JSON-LD y API oficial
  de Mercado Libre)
- `GET /api/v1/deals`, `GET /api/v1/deals/{id}` (feed, detalle e historial)
- `POST /api/v1/deals` y rutas privadas de bookmarks, votos y comentarios
- `GET|PUT /api/v1/user/profile`, `PUT /api/v1/user/password`
- `GET /api/v1/user/deals`, `PATCH /api/v1/user/deals/{id}/status` y
  `GET /api/v1/user/bookmarks`

Las rutas privadas requieren `Authorization: Bearer <token>`. Las contraseñas se
guardan con hash y cada robot, ejecución y clave API pertenece al usuario
autenticado.

La vista previa intenta leer nombre, descripción, imagen, precios, moneda,
descuento y especificaciones públicas de JSON-LD/Schema.org, etiquetas Open
Graph y selectores semánticos de precio. Reconoce tiendas como Coppel,
AliExpress, Liverpool, Nike y Adidas para etiquetar correctamente el origen.
También devuelve el HTML fuente recibido (hasta 2 MB) para que la interfaz lo
muestre como texto en su consola de vista previa; nunca se ejecuta en el
navegador. Los detalles estructurados se guardan en `deals.product_details`. Cada tienda
decide qué datos expone: páginas que requieren JavaScript, protecciones anti-bot
o credenciales de afiliado pueden responder incompletas y requerir captura
manual. Para Mercado Libre se consultan el artículo y su descripción mediante
la API pública cuando esta permite la petición.

El scraper extrae título, metadatos, encabezados, texto, enlaces, JSON-LD,
coincidencias de consultas y salidas Markdown/HTML/JSON. `Crawl` recorre como
máximo cinco páginas del mismo host por ejecución. Se limita la longitud de
respuestas, no se siguen redirecciones y se bloquean IPs privadas y reservadas.
PDF requiere un extractor adicional y se informa explícitamente si se intenta.

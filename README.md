[![Sitio en producción](https://www.funesya.com.ar/assets/captura2026.png)](https://www.funesya.com.ar/)

> Accedé al sitio en producción: **[https://www.funesya.com.ar/](https://www.funesya.com.ar/)**

# FunesYa

![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)
![License](https://img.shields.io/badge/licencia-MIT-22c55e)
![Estado](https://img.shields.io/badge/estado-producción-22c55e)

**Portal de noticias locales de Funes, Santa Fe** — agrega artículos en tiempo real desde múltiples medios locales, genera resúmenes con IA y los sirve como una web rápida y sin dependencias externas.

---

## Tabla de contenidos

- [FunesYa](#funesya)
  - [Tabla de contenidos](#tabla-de-contenidos)
  - [Características principales](#características-principales)
  - [Arquitectura del proyecto](#arquitectura-del-proyecto)
  - [Requisitos previos](#requisitos-previos)
  - [Instalación](#instalación)
    - [Con Docker (recomendado)](#con-docker-recomendado)
    - [En local sin Docker (desarrollo)](#en-local-sin-docker-desarrollo)
  - [Uso básico](#uso-básico)
    - [Ver el portal](#ver-el-portal)
    - [Actualizar noticias manualmente](#actualizar-noticias-manualmente)
    - [Verificar el estado del agregador](#verificar-el-estado-del-agregador)
    - [Consumir la API de noticias](#consumir-la-api-de-noticias)
  - [Configuración avanzada](#configuración-avanzada)
    - [Variables de entorno (`.env`)](#variables-de-entorno-env)
    - [Agregar nuevas fuentes RSS](#agregar-nuevas-fuentes-rss)
    - [Agregar un scraper (sitio sin RSS)](#agregar-un-scraper-sitio-sin-rss)
    - [Ajustar dominios con hotlink protection](#ajustar-dominios-con-hotlink-protection)
    - [Frecuencia del cron](#frecuencia-del-cron)
  - [Despliegue en producción](#despliegue-en-producción)
  - [Scripts de mantenimiento](#scripts-de-mantenimiento)
  - [Contribución](#contribución)
    - [Convenciones de commits](#convenciones-de-commits)
  - [Licencia](#licencia)
  - [Créditos](#créditos)

---

## Características principales

- **Agregación automática**: consume feeds RSS y scraping directo de medios que no publican RSS (FM Diez Funes, etc.), actualizándose cada 2 minutos vía cron.
- **Resúmenes con IA**: scrapea el artículo original y genera un resumen en español usando Google Gemini 1.5 Flash. El resumen se pre-genera en background para que el usuario vea la página instantáneamente.
- **Proxy de imágenes con caché**: descarga y cachea en disco las imágenes de medios con hotlink protection para servírlas sin cortes. Soporta redimensionado on-the-fly.
- **ETag / 304**: el endpoint de noticias (`/api/news.php`) implementa caché HTTP inteligente; el frontend solo descarga datos cuando hay cambios reales.
- **SEO completo**: sitemap XML dinámico, metaetiquetas Open Graph y Twitter Card, JSON-LD (WebSite + NewsArticle), URLs canónicas y compresión Gzip.
- **Alertas por Telegram**: notificación automática cuando la cobertura de imágenes de una fuente cae por debajo del umbral configurado.
- **Sin dependencias de Composer**: todo PHP puro, sin frameworks. La única dependencia externa es la API de Google Gemini.
- **Docker first**: imagen lista para producción basada en `php:8.2-apache` con cron integrado.

---

## Arquitectura del proyecto

```
/                          ← raíz (infraestructura, config)
├── public/                ← document root de Apache (único directorio web-accesible)
│   ├── index.php          ← portada: grid de noticias con SSR
│   ├── article.php        ← página de artículo individual
│   ├── about.php          ← página "Acerca de"
│   ├── sitemap.php        ← sitemap XML dinámico
│   ├── robots.txt
│   ├── rss.xml            ← feed RSS estático
│   ├── .htaccess          ← reglas de rewrite, cabeceras de seguridad, Gzip
│   ├── api/
│   │   ├── news.php       ← REST: lista de noticias con soporte ETag/304
│   │   ├── img.php        ← proxy de imágenes con caché de disco
│   │   ├── summary.php    ← genera/devuelve el resumen IA de un artículo
│   │   └── health.php     ← estado del agregador y estadísticas por fuente
│   └── assets/            ← CSS, JS y fuentes locales (Inter + Outfit)
├── src/                   ← clases PHP (privadas, fuera del reach del navegador)
│   ├── Aggregator.php          ← orquesta RSS + scraping, guarda en DB
│   ├── ArticleLoader.php       ← carga un artículo con su resumen para la vista
│   ├── ArticleSummarizer.php   ← scraping + llamada a Gemini + persistencia
│   ├── Config.php              ← lee .env, expone feeds/scrapers/dominios proxy
│   ├── Database.php            ← capa SQLite (PDO)
│   ├── TelegramNotifier.php    ← envía alertas al bot de Telegram
│   ├── ImageCoverageAlertService.php ← evalúa cobertura de imágenes por fuente
│   └── FileAlertStateStore.php ← persiste estado de alertas en disco (cooldown)
├── scripts/               ← CLI only (el cron los ejecuta, no el navegador)
│   └── run_aggregator.php ← punto de entrada del cron (flock para exclusión mutua)
├── data/                  ← generado en runtime, ignorado en git
│   ├── news.sqlite        ← base de datos principal
│   ├── img_cache/         ← caché de imágenes proxiadas
│   ├── feed_cache_headers.json ← ETags/Last-Modified de los feeds RSS
│   └── aggregator_status.json  ← estado del último ciclo de agregación
├── router.php             ← router para `php -S` en desarrollo local
├── Dockerfile
├── docker-compose.yml
├── docker-entrypoint.sh
└── deploy.sh              ← deploy en un comando (git pull + docker compose up)
```

**Flujo de datos:**

```
Cron (cada 2 min)
   └── scripts/run_aggregator.php
         └── Aggregator::fetchAll()
               ├── Feeds RSS  → parse XML → Database::save()
               └── Scraping   → parse HTML → Database::save()
                     └── ArticleSummarizer::getSummary()  (pre-generación)
                           └── Google Gemini API

Usuario abre artículo
   └── public/article.php
         └── ArticleLoader::load()
               ├── Resumen ya en DB → responde de inmediato
               └── Sin resumen → api/summary.php (async fetch desde el cliente)
```

---

## Requisitos previos

| Herramienta | Versión mínima | Notas |
|---|---|---|
| Docker | 24.x | Incluye Docker Compose v2 |
| PHP (dev local) | 8.2 | Solo para `php -S`; no necesario con Docker |
| Cuenta Google AI Studio | — | Para obtener `GEMINI_API_KEY` (gratuita) |
| Bot de Telegram | — | Opcional, solo para alertas |

---

## Instalación

### Con Docker (recomendado)

```bash
# 1. Clonar el repositorio
git clone https://github.com/tu-usuario/funesya.git
cd funesya

# 2. Copiar y completar las variables de entorno
cp .env.example .env
# Edita .env y agrega tu GEMINI_API_KEY

# 3. Levantar el contenedor
docker compose up -d --build

# 4. Ejecutar el primer ciclo de agregación manualmente
docker compose exec app php scripts/run_aggregator.php

# 5. Abrir en el navegador
open http://localhost:8080
```

### En local sin Docker (desarrollo)

```bash
# 1. Clonar e instalar
git clone https://github.com/tu-usuario/funesya.git
cd funesya
cp .env.example .env   # completar GEMINI_API_KEY

# 2. Crear directorio de datos
mkdir -p data/img_cache

# 3. Lanzar el servidor de desarrollo
php -S 127.0.0.1:9000 router.php

# 4. En otra terminal, ejecutar el agregador
php scripts/run_aggregator.php

# 5. Abrir http://127.0.0.1:9000
```

> **Nota**: la extensión `pdo_sqlite` debe estar habilitada en tu PHP local. Verificá con `php -m | grep sqlite`.

---

## Uso básico

### Ver el portal

Abrí `http://localhost:8080` (Docker) o `http://127.0.0.1:9000` (local). La portada muestra las últimas 12 noticias con carga infinita por scroll.

### Actualizar noticias manualmente

```bash
# Con Docker
docker compose exec app php scripts/run_aggregator.php

# En local
php scripts/run_aggregator.php
```

### Verificar el estado del agregador

```bash
curl http://localhost:8080/api/health.php | jq .
```

Ejemplo de respuesta:

```json
{
  "status": "success",
  "last_update": "2026-04-19 14:32:00",
  "aggregator": {
    "processed": 47,
    "saved": 3
  },
  "sources": {
    "InfoFunes": {
      "total_articles": 210,
      "recent_articles": 8,
      "fetch_state": "ok"
    }
  }
}
```

### Consumir la API de noticias

```bash
# Últimas 12 noticias
curl http://localhost:8080/api/news.php

# Filtrar por fuente y paginar
curl "http://localhost:8080/api/news.php?source=InfoFunes&page=2"
```

---

## Configuración avanzada

### Variables de entorno (`.env`)

| Variable | Requerida | Descripción |
|---|---|---|
| `GEMINI_API_KEY` | **Sí** | API key de Google AI Studio para generar resúmenes |
| `TELEGRAM_BOT_TOKEN` | No | Token del bot para alertas de cobertura de imágenes |
| `TELEGRAM_CHAT_ID` | No | ID del chat/canal donde llegan las alertas |
| `APP_TIMEZONE` | No | Zona horaria (default: `America/Argentina/Buenos_Aires`) |

### Agregar nuevas fuentes RSS

Editá `src/Config.php`:

```php
public static function getFeeds(): array
{
    return [
        'InfoFunes'       => 'https://infofunes.com.ar/rss.xml',
        'Nueva Fuente'    => 'https://nueva-fuente.com.ar/rss',  // ← agregar aquí
    ];
}
```

### Agregar un scraper (sitio sin RSS)

```php
public static function getScrapers(): array
{
    return [
        'FM Diez Funes' => 'https://www.fmdiezfunes.com.ar/noticias.php',
        'Nuevo Sitio'   => 'https://nuevosite.com.ar/noticias',  // ← agregar aquí
    ];
}
```

### Ajustar dominios con hotlink protection

Los dominios en `Config::getProxyDomains()` son servidos a través de `api/img.php` para evitar imágenes rotas. Si agregás una fuente cuyas imágenes se rompen, añadí su dominio:

```php
public static function getProxyDomains(): array
{
    return [
        'lavozdefunes.com.ar',
        'nuevo-dominio-con-hotlink.com',  // ← agregar aquí
    ];
}
```

### Frecuencia del cron

La frecuencia está definida en el `Dockerfile`:

```dockerfile
RUN echo "*/2 * * * * www-data /usr/local/bin/php /var/www/html/scripts/run_aggregator.php ..." \
    > /etc/cron.d/funesya
```

Cambiá `*/2` por el intervalo deseado (en minutos).

---

## Despliegue en producción

El repositorio incluye `deploy.sh` para desplegar en un servidor con Docker en un solo comando:

```bash
./deploy.sh
```

Esto ejecuta: `git pull` → `docker compose up -d --build` → primera agregación → muestra logs.

**Configuración de Apache en producción**: el `Dockerfile` cambia el `DocumentRoot` a `/var/www/html/public/`, por lo que `src/`, `scripts/` y `data/` son completamente inaccesibles desde el navegador.

---

## Scripts de mantenimiento

Los scripts en `scripts/` se ejecutan exclusivamente desde CLI:

| Script | Descripción |
|---|---|
| `run_aggregator.php` | Ciclo completo de agregación (usado por el cron) |
| `check_summaries.php` | Verifica resúmenes incompletos o truncados |
| `fix_summaries.php` | Regenera resúmenes fallidos |
| `fix_broken_image_urls.php` | Repara URLs de imágenes malformadas |
| `fix_images.php` | Actualiza imágenes faltantes desde los artículos originales |
| `check_dupes.php` | Detecta artículos duplicados en la DB |
| `clean_mocks.php` | Elimina artículos de ejemplo generados en tests |
| `backfill_canonical_keys.php` | Migración: rellena claves canónicas en artículos existentes |

```bash
# Ejemplo: regenerar resúmenes fallidos
docker compose exec app php scripts/fix_summaries.php
```

---

## Contribución

¡Las contribuciones son bienvenidas! Seguí estos pasos:

1. **Fork** del repositorio y creá una rama descriptiva:
   ```bash
   git checkout -b feat/nueva-fuente-rss
   ```

2. **Desarrollá** tu cambio siguiendo las convenciones del proyecto:
   - PHP 8.2+ con `declare(strict_types=1)` en cada archivo
   - Sin frameworks ni Composer; PHP puro
   - Nombrá las clases en PascalCase y los métodos en camelCase
   - Documentá con PHPDoc los métodos públicos

3. **Probá** localmente antes de hacer PR:
   ```bash
   php -S 127.0.0.1:9000 router.php
   php scripts/run_aggregator.php
   ```

4. **Abrí un Pull Request** describiendo:
   - Qué problema resuelve
   - Cómo probaste el cambio
   - Si aplica, capturas de pantalla

5. Para **reportar bugs** o proponer features, abrí un [Issue](../../issues) con el template correspondiente.

### Convenciones de commits

```
feat: agrega soporte para feed de Radio Fónica
fix: corrige URLs de imágenes con caracteres no-ASCII
perf: reduce latencia del proxy de imágenes con caché en memoria
```

---

## Licencia

Este proyecto está licenciado bajo la **Licencia MIT**. Podés usar, modificar y distribuir el código libremente, siempre que incluyas el aviso de copyright original.

Consultá el archivo [LICENSE](LICENSE) para más detalles.

---

## Créditos

**Desarrollado por** Ignacio D. López

**Tecnologías y servicios utilizados:**

| Tecnología | Uso |
|---|---|
| [PHP 8.2](https://www.php.net/) | Backend, plantillas HTML, scripts CLI |
| [SQLite 3](https://www.sqlite.org/) | Base de datos embebida, cero configuración |
| [Apache 2.4](https://httpd.apache.org/) | Servidor web con mod_rewrite |
| [Docker](https://www.docker.com/) | Contenedorización y cron integrado |
| [Google Gemini 1.5 Flash](https://ai.google.dev/) | Generación de resúmenes con IA |
| [Telegram Bot API](https://core.telegram.org/bots/api) | Alertas de monitoreo |
| [Inter](https://rsms.me/inter/) + [Outfit](https://fonts.google.com/specimen/Outfit) | Tipografías (servidas localmente) |

**Fuentes de noticias agregadas:**
- [InfoFunes](https://infofunes.com.ar)
- [La Voz de Funes](https://lavozdefunes.com.ar)
- [Funes Hoy](https://funeshoy.com.ar)
- [El Occidental](https://eloccidental.com.ar)
- [Estacionline](https://estacionline.com)
- [FM Diez Funes](https://www.fmdiezfunes.com.ar)

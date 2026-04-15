/**
 * Lógica principal del frontend de FunesYa.
 * Consume la API REST, renderiza tarjetas de noticias,
 * gestiona filtros por fuente y detecta noticias nuevas en tiempo real.
 */
document.addEventListener('DOMContentLoaded', () => {
    // Referencias a elementos del DOM
    const newsContainer = document.getElementById('news-container');
    const sourceFilters = document.getElementById('source-filters');
    const refreshBtn = document.getElementById('refresh-btn');
    const loader = document.getElementById('loader');
    
    let currentSource = 'Todas';
    let availableSources = ['Todas'];
    let currentPage = 1;
    let shownIds = new Set();      // IDs de noticias ya mostradas en pantalla
    let lastKnownUpdate = null;    // Último timestamp de actualización conocido

    const loadMoreBtn = document.getElementById('load-more-btn');
    const loadMoreContainer = document.getElementById('load-more-container');

    let refreshTimer;

    /**
     * Consulta la API evitando depender del caché HTTP del navegador.
     * Si el servidor responde 304, retorna null para que el caller conserve la UI actual.
     * @param {string} url - URL de la API.
     * @returns {Promise<Object|null>}
     */
    const fetchApiJson = async (url) => {
        const res = await fetch(url, {
            cache: 'no-store',
            headers: {
                'Cache-Control': 'no-cache',
            },
        });

        if (res.status === 304) {
            return null;
        }

        if (!res.ok) {
            throw new Error(`Error HTTP: ${res.status}`);
        }

        return res.json();
    };

    /** Escapa caracteres HTML para evitar XSS al insertar datos externos en el DOM. */
    const escHtml = (str) => String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const isStockImage = (url = '') => /picsum\.photos|images\.unsplash\.com/i.test(String(url));
    const hasUsableImage = (url = '') => {
        const normalized = String(url ?? '').trim();
        return normalized !== '' && !isStockImage(normalized);
    };

    /**
     * Dominios con hotlink protection activa.
     * Estacionline devuelve 403; La Voz de Funes y otros WordPress devuelven 200
     * con una imagen de "acceso denegado", por lo que onerror nunca dispara.
     * Para estos dominios se usa el proxy directamente, sin esperar al fallo.
     */
    const PROXY_DOMAINS = [
        'lavozdefunes.com.ar',
        'estacionline.com',
        'flex-assets.tadevel-cdn.com',
        'funeshoy.com.ar',
        'eloccidental.com.ar',
        'fmdiezfunes.com.ar',
        'infobae.com',
        'tn.com.ar',
        'radiofonica.com',
        'ambito.com',
        'media.ambito.com',
        'elliberador.com',
        'resizer.glanacion.com',
    ];

    /**
     * Devuelve la URL del proxy para una imagen externa.
     * @param {string} url - URL original de la imagen.
     * @returns {string}
     */
    const proxyUrl = (url) => `api/img.php?url=${encodeURIComponent(url)}`;

    /**
     * Devuelve la URL a usar para cargar una imagen.
     * Si el dominio tiene hotlink protection conocida, usa el proxy directamente.
     * De lo contrario usa la URL directa (más rápido, sin overhead).
     * @param {string} url - URL original de la imagen.
     * @returns {string}
     */
    const resolveImgSrc = (url) => {
        if (!hasUsableImage(url)) {
            return '';
        }

        try {
            const host = new URL(url).hostname;
            if (PROXY_DOMAINS.some(d => host === d || host.endsWith('.' + d))) {
                return proxyUrl(url);
            }
        } catch (_) { /* URL inválida: usar directa */ }
        return url;
    };

    const getSourceInitials = (source = '') => {
        const words = String(source).trim().split(/\s+/).filter(Boolean);
        if (words.length === 0) return 'FN';
        if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
        return words.slice(0, 2).map(word => word.charAt(0).toUpperCase()).join('');
    };

    const buildNoImagePlaceholder = (source = '') => `
        <div class="card-media-placeholder" aria-hidden="true">
            <div class="card-media-glyph">${escHtml(getSourceInitials(source))}</div>
            <div class="card-media-text">Cobertura sin imagen</div>
        </div>
    `;

    const markImageAsUnavailable = (img) => {
        const wrapper = img?.closest('.card-img-wrapper');
        if (img) {
            img.remove();
        }
        if (wrapper) {
            wrapper.classList.add('no-image');
            if (!wrapper.querySelector('.card-media-placeholder')) {
                wrapper.insertAdjacentHTML('afterbegin', buildNoImagePlaceholder(wrapper.dataset.source || ''));
            }
        }
    };

    /**
     * Adjunta el handler de error a un <img>:
     * si falla la carga directa intenta una vez por proxy y, si vuelve a fallar,
     * deja la tarjeta sin imagen en lugar de mostrar una foto de stock.
     * @param {HTMLImageElement} img        - Elemento imagen.
     * @param {string}           originalSrc - URL original de la imagen.
     */
    const attachImgFallback = (img, originalSrc) => {
        if (!img || !hasUsableImage(originalSrc)) {
            markImageAsUnavailable(img);
            return;
        }

        img.onerror = () => {
            const px = proxyUrl(originalSrc);
            if (img.src !== px) {
                img.src = px;
                return;
            }

            img.onerror = null;
            markImageAsUnavailable(img);
        };
    };

    /**
     * Crea un elemento <article> de tarjeta de noticia listo para insertar en el DOM.
     * @param {Object}  item  - Objeto noticia proveniente de la API.
     * @param {boolean} isNew - Si es true, agrega la clase 'new-card' para la animación.
     * @returns {HTMLElement}
     */
    const createCard = (item, isNew = false) => {
        const card = document.createElement('article');
        card.className = `news-card${isNew ? ' new-card' : ''}`;

        const hasImage = hasUsableImage(item.image_url);
        const imgSrc = hasImage ? resolveImgSrc(item.image_url) : '';

        card.innerHTML = `
            <div class="card-img-wrapper${hasImage ? '' : ' no-image'}" data-source="${escHtml(item.source)}">
                ${hasImage
                    ? `<img src="${escHtml(imgSrc)}" alt="${escHtml(item.title)}" loading="lazy" width="640" height="360">`
                    : buildNoImagePlaceholder(item.source)}
                <span class="card-source">${escHtml(item.source)}</span>
            </div>
            <div class="card-content">
                <h2 class="card-title">${escHtml(item.title)}</h2>
                <div class="card-footer">
                    <span class="card-date">${formatDate(item.pub_date)}</span>
                    <a href="article.php?id=${item.id}" class="read-more">Leer artículo</a>
                </div>
            </div>
        `;

        const img = card.querySelector('img');
        if (img) {
            attachImgFallback(img, item.image_url);
        }

        return card;
    };

    /**
     * Obtiene noticias desde la API y actualiza la UI.
     * @param {boolean} forceUpdate - Si es true, limpia el contenedor antes de renderizar.
     * @param {number}  page        - Número de página para la paginación.
     */
    const fetchNews = async (forceUpdate = false, page = 1) => {
        if (forceUpdate || page === 1) {
            if (page === 1) {
                newsContainer.innerHTML = '';
            }
            loader.classList.remove('hidden');
            loadMoreContainer.classList.add('hidden');
        } else {
            // Estado de carga en el botón "Cargar más"
            loadMoreBtn.textContent = 'Cargando...';
            loadMoreBtn.disabled = true;
        }

        try {
            let urlParams = `?page=${page}`;
            if (currentSource !== 'Todas') {
                urlParams += `&source=${encodeURIComponent(currentSource)}`;
            }
            const url = `api/news.php${urlParams}`;
            const jsonData = await fetchApiJson(url);

            if (jsonData === null) {
                return;
            }

            if (jsonData.status === 'success') {
                lastKnownUpdate = jsonData.last_update;
                updateFilters(jsonData.sources);
                renderNews(jsonData.data, page === 1);

                currentPage = jsonData.page;

                if (jsonData.has_more) {
                    loadMoreContainer.classList.remove('hidden');
                } else {
                    loadMoreContainer.classList.add('hidden');
                }
            } else {
                console.error('Error from API:', jsonData.message);
                if (page === 1) newsContainer.innerHTML = '<p class="error">No se pudieron cargar las noticias. Intente nuevamente.</p>';
            }
        } catch (error) {
            console.error('Networking Error:', error);
            if (newsContainer.innerHTML.trim() === '') {
                newsContainer.innerHTML = '<p class="error">Error al obtener las noticias. Verifique su conexión o que el servidor PHP esté activo.</p>';
            }
        } finally {
            loader.classList.add('hidden');
            refreshBtn.classList.remove('loading');
            refreshBtn.style.animation = '';
            loadMoreBtn.textContent = 'Cargar más noticias';
            loadMoreBtn.disabled = false;
        }
    };

    /**
     * Actualiza los botones de filtro por fuente solo cuando la lista cambia.
     * @param {string[]} sources - Array de nombres de fuentes disponibles.
     */
    const updateFilters = (sources) => {
        // Solo re-renderiza si la lista de fuentes cambió
        if (JSON.stringify(sources) !== JSON.stringify(availableSources)) {
            availableSources = sources;
            sourceFilters.innerHTML = '';
            
            sources.forEach(source => {
                const btn = document.createElement('button');
                btn.className = `pill ${source === currentSource ? 'active' : ''}`;
                btn.textContent = source;
                btn.dataset.source = source;
                
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.pill').forEach(p => p.classList.remove('active'));
                    btn.classList.add('active');
                    currentSource = source;
                    currentPage = 1;
                    shownIds.clear();
                    lastKnownUpdate = null;
                    fetchNews(true, 1); // Limpia pantalla al cambiar de fuente
                });
                
                sourceFilters.appendChild(btn);
            });
        }
    };

    /**
     * Formatea una fecha ISO a formato legible en español argentino.
     * @param {string} dateString - Fecha en formato ISO 8601.
     * @returns {string} Fecha formateada, ej: "02 abr 2026".
     */
    const formatDate = (dateString) => {
        const options = { day: '2-digit', month: 'short', year: 'numeric' };
        const d = new Date(dateString);
        return d.toLocaleDateString('es-AR', options);
    };

    /**
     * Crea y agrega tarjetas de noticias al DOM.
     * @param {Array}   newsData       - Lista de objetos noticia provenientes de la API.
     * @param {boolean} clearContainer - Si es true, limpia el contenedor antes de insertar.
     */
    const renderNews = (newsData, clearContainer = true) => {
        if (!newsData || newsData.length === 0) {
            if (clearContainer) newsContainer.innerHTML = '<p>No se encontraron noticias para esta fuente.</p>';
            return;
        }

        if (clearContainer) {
            newsContainer.innerHTML = '';
            shownIds.clear();
        }
        
        newsData.forEach(item => {
            shownIds.add(item.id);
            newsContainer.appendChild(createCard(item));
        });
    };

    /**
     * Inserta noticias nuevas al inicio del contenedor con animación de entrada.
     * @param {Array} newItems - Noticias que aún no están en pantalla.
     */
    const prependNews = (newItems) => {
        // Iterar en reversa para mantener el orden cronológico al hacer prepend
        [...newItems].reverse().forEach(item => {
            shownIds.add(item.id);
            newsContainer.prepend(createCard(item, true));
        });
    };

    /**
     * Muestra una notificación no interactiva cuando llegan noticias nuevas.
     * Se oculta automáticamente después de 4 segundos.
     * @param {number} count - Cantidad de noticias nuevas.
     */
    const showNewsBanner = (count) => {
        const existing = document.getElementById('new-news-banner');
        if (existing) {
            existing.querySelector('.banner-text').textContent =
                `${count} nueva${count > 1 ? 's noticias' : ' noticia'} disponible${count > 1 ? 's' : ''}`;
            return;
        }

        const banner = document.createElement('div');
        banner.id = 'new-news-banner';
        banner.className = 'new-news-banner';
        banner.innerHTML = `<span class="banner-text">${count} nueva${count > 1 ? 's noticias' : ' noticia'} disponible${count > 1 ? 's' : ''}</span>`;

        newsContainer.parentElement.insertBefore(banner, newsContainer);

        setTimeout(() => banner.remove(), 4000);
    };

    // ── Eventos ─────────────────────────────────────────────────────────
    loadMoreBtn.addEventListener('click', () => {
        fetchNews(false, currentPage + 1);
    });

    refreshBtn.addEventListener('click', () => {
        currentPage = 1;
        shownIds.clear();
        lastKnownUpdate = null;
        fetchNews(true, 1);
        startAutoRefresh(); // reinicia el temporizador
    });

    /**
     * Sondea la API cada 30 segundos. Si detecta noticias nuevas las inserta
     * automáticamente al inicio del feed sin recargar la página.
     */
    const startAutoRefresh = () => {
        if (refreshTimer) clearInterval(refreshTimer);
        refreshTimer = setInterval(async () => {
            try {
                let urlParams = `?page=1`;
                if (currentSource !== 'Todas') {
                    urlParams += `&source=${encodeURIComponent(currentSource)}`;
                }
                const jsonData = await fetchApiJson(`api/news.php${urlParams}`);
                if (jsonData === null) return;

                // Si no hubo cambios desde la última consulta, no hacer nada
                if (jsonData.status !== 'success' || jsonData.last_update === lastKnownUpdate) return;

                lastKnownUpdate = jsonData.last_update;
                updateFilters(jsonData.sources);

                // Filtrar solo las noticias que aún no están en pantalla
                const newItems = jsonData.data.filter(item => !shownIds.has(item.id));
                if (newItems.length > 0) {
                    prependNews(newItems);
                    showNewsBanner(newItems.length);
                    // Quitar el outline de highlight después de 5s
                    setTimeout(() => {
                        document.querySelectorAll('.news-card.new-card')
                            .forEach(c => c.classList.remove('new-card'));
                    }, 5000);
                }
            } catch (e) {
                // Fallo silencioso: no interrumpir la experiencia del usuario
            }
        }, 30 * 1000); // Sondeo cada 30 segundos
    };

    // ── Inicialización ───────────────────────────────────────────────────
    const ssr = window.__SSR__;
    if (ssr && ssr.ids && ssr.ids.length > 0) {
        // El servidor ya rindió las tarjetas en HTML: solo hidratamos el estado JS.
        ssr.ids.forEach(id => shownIds.add(id));
        lastKnownUpdate = ssr.lastUpdate;
        currentPage     = 1;
        if (ssr.sources) updateFilters(ssr.sources);
        if (ssr.hasMore)  loadMoreContainer.classList.remove('hidden');

        // Adjuntar el handler de error/fallback a las imágenes SSR
        document.querySelectorAll('#news-container article img[data-original-src]').forEach(img => {
            attachImgFallback(img, img.dataset.originalSrc);
        });
    } else {
        // Fallback: base de datos vacía o sin PHP, cargar vía API
        fetchNews(true);
    }
    startAutoRefresh();
});

/**
 * Quince Search Score: tracker frontowy
 *
 * Śledzi dwie rzeczy:
 *   1) Frazy wyszukiwania: gdy klient wysyła wyszukiwanie
 *   2) Kliknięcia w produkt z kontekstu wyszukiwania: na liście wyników
 *
 * Kontekst wyszukiwania przetrwa zmiany filtrów i sortowania (sessionStorage),
 * znika przy wyjściu ze stron wyszukiwania/produktu.
 *
 * Ładowane hookiem `displayHeader`. Wymaga zmiennej globalnej `qssTrackUrl`
 * (ustawianej przez moduł) wskazującej endpoint AJAX.
 */
(function () {
    'use strict';

    if (typeof qssTrackUrl === 'undefined') return;

    var STORAGE_KEY = 'qss_search_context';

    // =====================================================================
    // Pomocnicze
    // =====================================================================

    function sendTrack(data) {
        var params = [];
        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
            }
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', qssTrackUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.send(params.join('&'));
    }

    function getSearchContext() {
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function setSearchContext(phrase, source) {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                phrase: phrase,
                source: source,
                timestamp: Date.now()
            }));
        } catch (e) {}
    }

    function clearSearchContext() {
        try {
            sessionStorage.removeItem(STORAGE_KEY);
        } catch (e) {}
    }

    // Motyw Quince renderuje <body id="{$page.page_name}">, a natywny
    // kontroler wyszukiwania PrestaShopa ma page_name "search". Sprawdzamy
    // to najpierw, URL jest tylko zapasem na nietypową konfigurację.
    function isSearchPage() {
        return document.body.id === 'search' ||
               document.body.classList.contains('page-search') ||
               window.location.search.indexOf('controller=search') !== -1 ||
               window.location.pathname.indexOf('/search') !== -1;
    }

    // =====================================================================
    // 1) Śledzenie fraz
    // =====================================================================

    function trackPhrase(phrase, source) {
        phrase = (phrase || '').trim();
        if (!phrase || phrase.length < 2) return;

        source = source || 'searchbar';

        sendTrack({ type: 'phrase', phrase: phrase, source: source });
        setSearchContext(phrase, source);
    }

    // Dostępne globalnie, gdyby inny skrypt (searchbar, autocomplete) chciał
    // wywołać wprost zamiast polegać na autodetekcji z URL.
    window.qssTrackPhrase = trackPhrase;

    // =====================================================================
    // 2) Śledzenie kliknięć w produkt z kontekstu wyszukiwania
    // =====================================================================

    function trackClick(idProduct, source) {
        idProduct = parseInt(idProduct, 10);
        if (!idProduct || idProduct <= 0) return;

        source = source || 'listing';

        sendTrack({ type: 'click', id_product: idProduct, source: source });
    }

    window.qssTrackClick = trackClick;

    // =====================================================================
    // 3) Autodetekcja: fraza z URL na stronie wyników
    // =====================================================================

    function detectSearchFromUrl() {
        if (!isSearchPage()) {
            var isProductPage = document.body.id === 'product' || document.body.classList.contains('page-product');
            if (!isProductPage) {
                clearSearchContext();
            }
            return;
        }

        var urlParams = new URLSearchParams(window.location.search);
        var query = urlParams.get('s') || urlParams.get('search_query') || '';
        query = query.trim();

        if (!query) return;

        var ctx = getSearchContext();
        if (ctx && ctx.phrase === query.toLowerCase()) {
            return;
        }

        trackPhrase(query, 'listing');
    }

    // =====================================================================
    // 4) Autodetekcja: kliknięcia w produkt na liście wyników
    // =====================================================================

    function bindProductClicksOnListing() {
        if (!isSearchPage()) return;

        document.addEventListener('click', function (e) {
            if (!getSearchContext()) return;

            var link = e.target.closest('a[href]');
            if (!link) return;

            var productCard = link.closest('.js-product-miniature, .product-miniature, [data-id-product]');
            if (!productCard) return;

            var idProduct = productCard.getAttribute('data-id-product') || productCard.dataset.idProduct;
            if (idProduct) {
                trackClick(idProduct, 'listing');
            }
        });
    }

    // =====================================================================
    // Start
    // =====================================================================

    document.addEventListener('DOMContentLoaded', function () {
        detectSearchFromUrl();
        bindProductClicksOnListing();
    });

})();

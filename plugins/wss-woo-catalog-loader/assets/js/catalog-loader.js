(() => {
  'use strict';

  const cfg = window.WSS_WCL || {};

  const one = (selector, context = document) => {
    if (!selector) return null;
    try {
      return context.querySelector(selector);
    } catch (error) {
      return null;
    }
  };

  const all = (selector, context = document) => {
    if (!selector) return [];
    try {
      return Array.from(context.querySelectorAll(selector));
    } catch (error) {
      return [];
    }
  };

  // Version 1.1 intentionally targets classic WooCommerce archive markup.
  // If the configured products container is not present (for example on a
  // Product Collection Block archive), leave WooCommerce's native behavior
  // untouched instead of partially modifying the page.
  const products = one(cfg.productsSelector);
  if (!products) return;

  const pagination = one(cfg.paginationSelector);
  const getResultCounts = (root = document) => all(cfg.resultCountSelector, root);

  const mode = () => {
    if (
      window.innerWidth <= Number(cfg.mobileBreakpoint || 768) &&
      cfg.mobileOverride &&
      cfg.mobileMode
    ) {
      return cfg.mobileMode;
    }
    return cfg.mode || 'infinite';
  };

  function syncResultCountVisibility() {
    const shouldHide = Boolean(cfg.hideResultCount) && mode() !== 'pagination';
    getResultCounts().forEach((node) => {
      node.classList.toggle('wss-wcl-result-count-hidden', shouldHide);
    });
  }

  // Result count is independent from pagination. WooCommerce does not render
  // pagination when a catalog fits on one page, so handle the count first.
  syncResultCountVisibility();

  if (!pagination) {
    window.addEventListener('resize', syncResultCountVisibility, { passive: true });
    return;
  }

  let loading = false;
  let controller = null;
  let observer = null;
  let autoLoaded = 0;

  const originalUrl = window.location.href;

  const control = document.createElement('div');
  control.className = 'wss-wcl-control';
  control.setAttribute('aria-live', 'polite');
  control.innerHTML = '<div class="wss-wcl-status"></div><button type="button" class="button wss-wcl-button"></button>';
  pagination.insertAdjacentElement('afterend', control);

  const status = one('.wss-wcl-status', control);
  const button = one('.wss-wcl-button', control);

  const dispatch = (name, detail = {}) => {
    document.dispatchEvent(new CustomEvent(`wss:catalog:${name}`, { detail }));
  };

  const getNext = (root = document) => one(cfg.nextSelector, root)?.href || null;
  let nextUrl = getNext();

  function cacheKey(url) {
    return `wss-wcl:${url}`;
  }

  function cacheGet(url) {
    try {
      return cfg.sessionCache ? sessionStorage.getItem(cacheKey(url)) : null;
    } catch (error) {
      return null;
    }
  }

  function cacheSet(url, html) {
    try {
      if (cfg.sessionCache) sessionStorage.setItem(cacheKey(url), html);
    } catch (error) {
      // Storage can be unavailable or full. Loading still works without it.
    }
  }

  function setBusy(on, text = '') {
    loading = on;
    products.setAttribute('aria-busy', on ? 'true' : 'false');
    status.textContent = text;
    button.disabled = on;
  }

  function showButton(label = cfg.buttonText) {
    button.textContent = label;
    button.hidden = false;
  }

  function hideButton() {
    button.hidden = true;
  }

  function syncPaginationVisibility() {
    pagination.hidden = mode() !== 'pagination';
  }

  function updateControls() {
    syncPaginationVisibility();
    syncResultCountVisibility();

    if (!nextUrl) {
      hideButton();
      status.textContent = '';
      observer?.disconnect();
      return;
    }

    const currentMode = mode();
    const autoLimit = Number(cfg.autoPages) || 0;
    const reachedAutoLimit = currentMode === 'infinite' && autoLimit > 0 && autoLoaded >= autoLimit;

    if (currentMode === 'load_more' || reachedAutoLimit) {
      showButton();
    } else {
      hideButton();
    }

    if (currentMode === 'infinite' && !reachedAutoLimit) {
      observeLoader();
    } else {
      observer?.disconnect();
    }
  }

  function observeLoader() {
    observer?.disconnect();

    if (!('IntersectionObserver' in window)) {
      showButton();
      return;
    }

    observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting) && !loading && nextUrl) {
          load(nextUrl, true);
        }
      },
      { rootMargin: `0px 0px ${Number(cfg.rootMargin || 600)}px 0px` }
    );

    observer.observe(control);
  }

  function parse(html) {
    return new DOMParser().parseFromString(html, 'text/html');
  }

  function appendPage(doc, url) {
    const source = one(cfg.productsSelector, doc);
    if (!source) throw new Error('Products container not found');

    const sourceItems = all(cfg.productSelector, source);
    if (!sourceItems.length) throw new Error('No products found');

    // Never add service markers to the product grid: grid/flex layouts and
    // :nth-child rules may treat them as product cards and distort the row.
    const importedItems = sourceItems.map((node) => document.importNode(node, true));
    products.append(...importedItems);

    nextUrl = getNext(doc);
    dispatch('loaded', { url, items: importedItems, nextUrl, replace: false });
  }

  async function load(url, automatic = false) {
    if (!url || loading) return;

    dispatch('before-load', { url });
    setBusy(true, cfg.loadingText);
    hideButton();

    controller?.abort();
    controller = new AbortController();

    try {
      let html = cacheGet(url);

      if (!html) {
        const response = await fetch(url, {
          credentials: 'same-origin',
          signal: controller.signal,
          headers: { 'X-WSS-Catalog-Loader': '1' },
        });

        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        html = await response.text();
        cacheSet(url, html);
      }

      appendPage(parse(html), url);
      if (automatic) autoLoaded++;

      setBusy(false, '');
      updateControls();
    } catch (error) {
      if (error.name === 'AbortError') return;
      setBusy(false, cfg.errorText);
      showButton(cfg.retryText);
      dispatch('error', { url, error });
    }
  }

  button.addEventListener('click', () => load(nextUrl, false));
  window.addEventListener('resize', updateControls, { passive: true });

  // AJAX pagination keeps normal links in the HTML for crawlability and for
  // users without JavaScript. Only ordinary left clicks are intercepted.
  pagination.addEventListener('click', async (event) => {
    if (mode() !== 'pagination') return;

    const link = event.target.closest('a');
    if (
      !link ||
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    ) {
      return;
    }

    event.preventDefault();
    const url = link.href;

    setBusy(true, cfg.loadingText);
    controller?.abort();
    controller = new AbortController();

    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        signal: controller.signal,
        headers: { 'X-WSS-Catalog-Loader': '1' },
      });

      if (!response.ok) throw new Error(`HTTP ${response.status}`);

      const doc = parse(await response.text());
      const source = one(cfg.productsSelector, doc);
      const newPagination = one(cfg.paginationSelector, doc);
      const currentResultCounts = getResultCounts();
      const newResultCounts = getResultCounts(doc);

      if (!source || !newPagination) throw new Error('Catalog fragments not found');

      products.innerHTML = source.innerHTML;
      pagination.innerHTML = newPagination.innerHTML;

      if (currentResultCounts.length && newResultCounts.length) {
        currentResultCounts.forEach((node, index) => {
          const sourceCount = newResultCounts[index] || newResultCounts[0];
          node.innerHTML = sourceCount.innerHTML;
        });
      }

      nextUrl = getNext(doc);
      autoLoaded = 0;

      if (cfg.history) {
        history.pushState({ wssWcl: true, mode: 'pagination' }, '', url);
      }

      const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
      window.scrollTo({
        top: products.getBoundingClientRect().top + window.scrollY - 24,
        behavior: reduceMotion ? 'auto' : 'smooth',
      });

      setBusy(false, '');
      updateControls();
      dispatch('loaded', {
        url,
        items: all(cfg.productSelector, products),
        replace: true,
        nextUrl,
      });
    } catch (error) {
      if (error.name !== 'AbortError') {
        setBusy(false, cfg.errorText);
        dispatch('error', { url, error });
      }
    }
  });

  window.addEventListener('popstate', () => {
    if (cfg.history && mode() === 'pagination') window.location.reload();
  });

  updateControls();
  dispatch('ready', { originalUrl, nextUrl, mode: mode() });
})();

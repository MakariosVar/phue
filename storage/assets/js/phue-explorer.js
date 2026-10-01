/**
 * Phue Explorer - Single Page Navigation Interceptor
 * Intercepts same-origin anchor clicks, fetches page HTML, updates #app & title,
 * and leaves the autonomous #nav root mounted without unmounting.
 */
(() => {
  if (window.__phueExplorerInitialized) return;
  window.__phueExplorerInitialized = true;

  // Inject the global [v-cloak] guard from the shell, before any module
  // script mounts, so the app never flashes unmounted content (R1). Module
  // scripts reuse the same style id, so this stays idempotent.
  if (!document.getElementById('phue-v-cloak-style')) {
    const style = document.createElement('style');
    style.id = 'phue-v-cloak-style';
    style.textContent = '[v-cloak] { display: none !important; }';
    document.head.appendChild(style);
  }

  /**
   * Updates active status classes and accessibility attributes on nav links during client transitions
   *
   * @param {string} targetUrl The current active URL
   */
  function updateNavigationState(targetUrl) {
    let targetPath = '';
    try {
      targetPath = new URL(targetUrl, window.location.origin).pathname;
    } catch {
      return; // Early return if targetUrl itself is malformed
    }

    document.querySelectorAll('#nav .nav-link, #nav .dropdown-item').forEach((a) => {
      const href = a.getAttribute('href');
      if (!href || href === '#') return;

      // Per-item error boundary to isolate malformed hrefs
      try {
        const linkUrl = new URL(href, window.location.origin);
        
        // Ignore non-http/https links like mailto: or tel:
        if (linkUrl.origin !== window.location.origin) {
          a.classList.remove('active');
          a.removeAttribute('aria-current');
          return;
        }

        const linkPath = linkUrl.pathname;
        const isActive = linkPath === '/' 
          ? targetPath === '/' 
          : (targetPath === linkPath || targetPath.startsWith(linkPath + '/'));

        a.classList.toggle('active', isActive);
        if (isActive) {
          a.setAttribute('aria-current', 'page');
        } else {
          a.removeAttribute('aria-current');
        }
      } catch {
        // Continue to next item if this specific href is malformed
        a.classList.remove('active');
        a.removeAttribute('aria-current');
      }
    });
  }

  /**
   * Dynamically loads a script module and executes its default/mount export
   * 
   * @param {string} scriptUrl Module URL to import
   */
  async function loadAndMountModule(scriptUrl) {
    if (scriptUrl.includes('PhNavigation') || scriptUrl.includes('phue-explorer')) {
      return;
    }

    const mod = await import(scriptUrl);

    // Invoke default export or explicit mount hook if present
    if (typeof mod.default === 'function') {
      mod.default();
    } else if (typeof mod.mountApp === 'function') {
      mod.mountApp();
    }
  }

  /**
   * Initial page mount handler for full page loads
   */
  document.addEventListener('DOMContentLoaded', () => {
    if(document.getElementById('app')?.__vue_app__) return;
    const scripts = document.querySelectorAll('script[type="module"][src*="/assets/phue/"]');
    scripts.forEach((script) => {
      const src = script.getAttribute('src');
      if (src) {
        loadAndMountModule(src);
      }
    });
  });

  // Module/outer-scope variable to hold the active AbortController
  let currentNavigationController = null;

  async function navigate(url, pushState = true) {
    // 0. Cancel any in-flight navigation request before starting a new one
    if (currentNavigationController) {
      currentNavigationController.abort();
    }
    
    const controller = new AbortController();
    currentNavigationController = controller;

    try {
      // 1. Fetch payload with signal attached
      const response = await fetch(url, {
        signal: controller.signal,
        headers: {
          'X-Phue-SPA': 'true'
        }
      });

      if (!response.ok) {
        window.location.href = url;
        return;
      }

      // 2. Parse payload
      const payload = await response.json();

      // 3. Commit URL BEFORE mounting so scripts reading window.location get the updated URL
      if (pushState) {
        window.history.pushState({}, '', url);
      }

      // 4. Update Document Title
      if (payload.title) {
        document.title = payload.title;
      }

      // 5. Update #app DOM content & State
      const appContainer = document.getElementById('app');
      const mounted = appContainer.__vue_app__;
      window.__phueApp = null;
      if (mounted && typeof mounted.unmount === 'function') mounted.unmount();
      
      // Clear children properly before innerHTML assignment
      while (appContainer.firstChild) {
        appContainer.removeChild(appContainer.firstChild);
      }
      appContainer.innerHTML = payload.html || '';
      window.__INITIAL_STATE__ = payload.state || {};

      // 6. Execute newly loaded page script modules
      if (Array.isArray(payload.scripts)) {
        (payload.modulePreload || []).forEach((url) => {
          if (document.querySelector(`link[rel="modulepreload"][href="${url}"]`)) return;
          const l = document.createElement('link');
          l.rel = 'modulepreload';
          l.href = url;
          document.head.appendChild(l);
        });
        for (const scriptUrl of payload.scripts) {
          // Stop execution if navigation was aborted mid-script loading
          if (controller.signal.aborted) return;
          await loadAndMountModule(scriptUrl);
        }
      }

      // 7. Sync navigation active state
      updateNavigationState(url);

      // 8. Handle Fragment Scrolling / Scroll Reset
      if (window.location.hash) {
        const targetEl = document.querySelector(window.location.hash);
        if (targetEl) {
          targetEl.scrollIntoView();
        } else {
          window.scrollTo(0, 0);
        }
      } else {
        window.scrollTo(0, 0);
      }

      // 9. Dispatch custom navigation event for reactive listeners
      window.dispatchEvent(new CustomEvent('phue:navigate', { 
        detail: { url, navState: payload.navState } 
      }));

    } catch (err) {
      // Ignore explicit cancellation errors
      if (err.name === 'AbortError') {
        return;
      }

      console.error('SPA Navigation error:', err);
      // Hard fallback on error (resets state cleanly if pushState occurred before mount failure)
      window.location.href = url;
    } finally {
      // Clear controller reference if this invocation was the active one
      if (currentNavigationController === controller) {
        currentNavigationController = null;
      }
    }
  }

  document.addEventListener('click', async (e) => {
    // 1. Ignore modifier keys & non-left clicks
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

    const link = e.target.closest('a[href]');
    if (!link) return;

    if (!(link instanceof HTMLAnchorElement)) return;

    // 2. Guard against download attributes, targets, rel="external", or opt-outs
    if (link.hasAttribute('download')) return;
    if (link.target && link.target !== '_self') return;
    if (link.relList.contains('external') || link.hasAttribute('data-no-spa')) return;

    const href = link.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;

    try {
      const resolvedUrl = new URL(href, window.location.href);
      if (resolvedUrl.origin === window.location.origin) {
        e.preventDefault();
        await navigate(resolvedUrl.href);
      }
    } catch {
      // Fallback to standard browser navigation
    }
});

  window.addEventListener('popstate', () => {
    navigate(window.location.href, false);
  });
})();
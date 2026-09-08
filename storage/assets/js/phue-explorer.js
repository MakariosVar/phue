/**
 * Phue Explorer - Single Page Navigation Interceptor
 * Intercepts same-origin anchor clicks, fetches page HTML, updates #app & title,
 * and re-evaluates module scripts safely to mount Vue components without memory leaks.
 */
(() => {
  if (window.__phueExplorerInitialized) return;
  window.__phueExplorerInitialized = true;

  document.addEventListener('click', async (e) => {
    // Ignore modified clicks (Cmd+click, Ctrl+click, Shift+click) or non-left click events
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

    const link = e.target.closest('a[href]');
    if (!link) return;

    const href = link.getAttribute('href');
    const target = link.getAttribute('target');

    // Safe origin check using new URL construction
    let isSameOrigin = false;
    try {
      const resolvedUrl = new URL(link.href, window.location.href);
      isSameOrigin = resolvedUrl.origin === window.location.origin;
    } catch {
      isSameOrigin = false;
    }

    // Skip external, new tab, download, fragment, or cross-origin links
    if (
      !href ||
      href.startsWith('#') ||
      href.startsWith('javascript:') ||
      target === '_blank' ||
      link.hasAttribute('download') ||
      !isSameOrigin
    ) {
      return;
    }

    e.preventDefault();
    await navigateTo(href);
  });

  window.addEventListener('popstate', async () => {
    await navigateTo(window.location.href, false);
  });

    /**
   * Fetches target URL content and updates DOM content and scripts.
   *
   * @param {string} url
   * @param {boolean} pushState
   */
  async function navigateTo(url, pushState = true) {
    try {
      const response = await fetch(url, {
        headers: { 'X-Phue-SPA': 'true' }
      });

      if (!response.ok) {
        window.location.href = url;
        return;
      }
      
      const data = await response.json();

      // 1. Update Document Title & Global State
      if (data.title) {
        document.title = data.title;
      }
      if (data.state) {
        window.__INITIAL_STATE__ = data.state;
      }

      // 2. Unmount old Vue & Update HTML
      if (window.__phueApp && typeof window.__phueApp.unmount === 'function') {
          window.__phueApp.unmount();
          window.__phueApp = null;
      }
      document.querySelector('#app').innerHTML = data.html;

      // 3. Update Browser History (Το είχες σβήσει κατά λάθος!)
      if (pushState) {
        window.history.pushState(null, '', url);
      }

      // 4. Mount Vue Components via Dynamic Imports (Πλέον με το JSON!)
      for (const script of data.scripts) {
          if (script.url.includes('/assets/vendor/')) continue;
          
          try {
              const mod = await import(script.url);
              if (typeof mod.default === 'function') {
                  mod.default();
              }
          } catch (err) {
              console.error('Failed to load module:', script.url);
          }
      }
    } catch (err) {
      console.error('[Phue Explorer] Navigation failed, falling back to full load:', err);
      window.location.href = url;
    }
  }

})();
/**
 * Phue Explorer - Single Page Navigation Interceptor
 * Intercepts same-origin anchor clicks, fetches page HTML, updates #app & title,
 * and leaves the autonomous #nav root mounted without unmounting.
 */
(() => {
  if (window.__phueExplorerInitialized) return;
  window.__phueExplorerInitialized = true;

  /**
   * Updates active status classes and accessibility attributes on nav links during client transitions
   *
   * @param {string} targetUrl The current active URL
   */
  function updateNavigationState(targetUrl) {
    const targetPath = new URL(targetUrl, window.location.origin).pathname;

    document.querySelectorAll('#nav .nav-link, #nav .dropdown-item').forEach((a) => {
      const href = a.getAttribute('href');
      if (!href || href === '#') return;

      const linkPath = new URL(href, window.location.origin).pathname;
      const isActive = linkPath === '/' ? targetPath === '/' : (targetPath === linkPath || targetPath.startsWith(linkPath + '/'));

      a.classList.toggle('active', isActive);
      if (isActive) {
        a.setAttribute('aria-current', 'page');
      } else {
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
    const scripts = document.querySelectorAll('script[type="module"][src*="/assets/phue/"]');
    scripts.forEach((script) => {
      const src = script.getAttribute('src');
      if (src) {
        loadAndMountModule(src);
      }
    });
  });

  async function navigate(url, pushState = true) {
    try {
      const response = await fetch(url, {
        headers: {
          'X-Phue-SPA': 'true'
        }
      });

      if (!response.ok) {
        window.location.href = url;
        return;
      }

      const payload = await response.json();

      // Update Document Title
      if (payload.title) {
        document.title = payload.title;
      }

      // Update #app content
      const appContainer = document.getElementById('app');
      if (appContainer && payload.html) {
        if (window.__phueApp && typeof window.__phueApp.unmount === 'function') {
          window.__phueApp.unmount();
          window.__phueApp = null;
        }

        appContainer.innerHTML = payload.html;
        window.__INITIAL_STATE__ = payload.state || {};
      }

      // Load new page scripts and execute module entrypoint
      if (Array.isArray(payload.scripts)) {
        for (const scriptUrl of payload.scripts) {
          await loadAndMountModule(scriptUrl);
        }
      }

      // Sync Navigation active state
      updateNavigationState(url);

      if (pushState) {
        window.history.pushState({}, '', url);
      }
    } catch (err) {
      console.error('SPA Navigation error:', err);
      window.location.href = url;
    }
  }

  document.addEventListener('click', async (e) => {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

    const link = e.target.closest('a[href]');
    if (!link) return;

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
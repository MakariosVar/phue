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

      const html = await response.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');

      // 1. Update Document Title
      if (doc.title) {
        document.title = doc.title;
      }

      // 2. Replace #app Content
      const newApp = doc.querySelector('#app');
      const currentApp = document.querySelector('#app');

      if (!newApp || !currentApp) {
        window.location.href = url;
        return;
      }

      // --- Unmount existing Vue instance before DOM swap ---
      if (window.__phueApp && typeof window.__phueApp.unmount === 'function') {
        window.__phueApp.unmount();
        window.__phueApp = null;
      }

      currentApp.innerHTML = newApp.innerHTML;

      // 3. Update Browser History
      if (pushState) {
        window.history.pushState(null, '', url);
      }

      // Update global state before remounting Vue
      const stateScript = Array.from(doc.querySelectorAll('script')).find(s => 
        !s.hasAttribute('type') && s.textContent.includes('window.__INITIAL_STATE__')
      );
      if (stateScript) {
        const newStateScript = document.createElement('script');
        newStateScript.textContent = stateScript.textContent;
        document.body.appendChild(newStateScript);
        newStateScript.remove();
      }


      // 4. Re-execute inline <script type="module"> tags for Vue re-mounting
      const scripts = doc.querySelectorAll('script[type="module"]');
      for (const oldScript of scripts) {
        if (oldScript.src) {
          // Ignore vendor scripts (e.g. Vue source)
          if (oldScript.src.includes('/assets/vendor/')) continue;
          // Dynamic import! If already in memory, returns instantly.
          import(oldScript.src).then(mod => {
            if (typeof mod.default === 'function') {
              mod.default(); // Mount the Vue component!
            }
          }).catch(console.error);
        } else {
          // Blob / inline scripts
          const blob = new Blob([oldScript.textContent], { type: 'text/javascript' });
          const blobUrl = URL.createObjectURL(blob);
          const newScript = document.createElement('script');
          newScript.type = 'module';
          newScript.src = blobUrl;
          
          const cleanup = () => {
            URL.revokeObjectURL(blobUrl);
            newScript.remove();
          };
          newScript.addEventListener('load', cleanup);
          newScript.addEventListener('error', cleanup);
          document.body.appendChild(newScript);
        }
      }
    } catch (err) {
      console.error('[Phue Explorer] Navigation failed, falling back to full load:', err);
      window.location.href = url;
    }
  }
})();
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

    // Skip external, new tab, download, or fragment links
    if (
      !href ||
      href.startsWith('#') ||
      href.startsWith('javascript:') ||
      target === '_blank' ||
      link.hasAttribute('download') ||
      link.origin !== window.location.origin
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

      currentApp.innerHTML = newApp.innerHTML;

      // 3. Update Browser History
      if (pushState) {
        window.history.pushState(null, '', url);
      }

      // 4. Re-execute inline <script type="module"> tags for Vue re-mounting with object URL revocation
      const scripts = doc.querySelectorAll('script[type="module"]');
      for (const oldScript of scripts) {
        const newScript = document.createElement('script');
        newScript.type = 'module';

        if (oldScript.src) {
          newScript.src = oldScript.src;
        } else {
          const blob = new Blob([oldScript.textContent], { type: 'text/javascript' });
          const blobUrl = URL.createObjectURL(blob);
          newScript.src = blobUrl;

          // Revoke Blob URL after execution to prevent memory leaks
          setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
        }

        document.body.appendChild(newScript);

        // Remove element from DOM body once evaluated
        setTimeout(() => newScript.remove(), 1000);
      }
    } catch (err) {
      console.error('[Phue Explorer] Navigation failed, falling back to full load:', err);
      window.location.href = url;
    }
  }
})();
; Each framework block is opaque text in the core grammar, so re-colour the
; inner spans through language injections, exactly like Zed's own Svelte and
; Vue extensions do. `combined` gathers all `content` fragments of a block
; into a single embedded buffer (Vue/HTML splits the template into several
; content nodes, so without it only the first fragment would be parsed).

(template_inner (content) @injection.content
  (#set! injection.language "html")
  (#set! injection.combined))

(script_inner (content) @injection.content
  (#set! injection.language "javascript")
  (#set! injection.combined))

(php_inner (content) @injection.content
  (#set! injection.language "php")
  (#set! injection.combined))
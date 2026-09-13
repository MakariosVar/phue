#include "tree_sitter/parser.h"
#include <stdbool.h>
#include <stdint.h>
#include <string.h>

/*
 * Phue SFC external scanner.
 *
 * Emits the six exact block tags (<template>, </template>, <script>,
 * </script>, <php>, </php>) and an opaque CONTENT token that always stops
 * before a block tag.
 *
 * Every invocation first runs the six-tag candidate automaton on the current
 * position regardless of `valid_symbols`:
 *   - if the run is exactly one of the six tags, that tag is emitted (when
 *     the parser accepts it) or nothing is returned (when the tag is not
 *     acceptable in this explore-path — the caller re-scans the same
 *     position for the alternative that can use the tag);
 *   - otherwise the already-consumed prefix plus the rest of the run up to
 *     the next '<' (or EOF) is emitted as a CONTENT span.
 *
 * This guarantees a CONTENT token is never handed out for a position that in
 * fact begins a block tag, so conflicted GLR branches can never "steal" the
 * opening '<' of a real block into a text node.
 */

enum TokenType {
  TOK_TEMPLATE_OPEN,
  TOK_TEMPLATE_CLOSE,
  TOK_SCRIPT_OPEN,
  TOK_SCRIPT_CLOSE,
  TOK_PHP_OPEN,
  TOK_PHP_CLOSE,
  TOK_CONTENT,
};

static const char *TAG_STRING[TOK_CONTENT] = {
    "<template>", "</template>", "<script>", "</script>", "<php>", "</php>",
};

static unsigned tag_length(enum TokenType t) {
  unsigned n = 0;
  while (TAG_STRING[t][n] != '\0') n++;
  return n;
}

static bool is_whitespace(int32_t ch) {
  return ch == ' ' || ch == '\t' || ch == '\n' || ch == '\r' ||
         ch == '\f' || ch == '\v' || ch == 0xFEFF;
}

/*
 * Try to match a complete block tag at the current position, advancing the
 * lexer through every byte that is still consistent with some candidate.
 * Returns true and emits nothing when the run is exactly a tag (the caller
 * decides whether that tag is acceptable); returns false otherwise, with
 * *consumed holding how many consistent prefix bytes were consumed.
 */
static bool scan_tag_run(TSLexer *lexer, enum TokenType *out, unsigned *consumed) {
  uint64_t alive = 0x3F;  // all six tags
  unsigned pos = 0;

  while (lexer->lookahead) {
    int32_t ch = lexer->lookahead;

    uint64_t surviving = 0;
    for (enum TokenType t = TOK_TEMPLATE_OPEN; t < TOK_CONTENT; t++) {
      if (!(alive & ((uint64_t)1 << (unsigned)t))) continue;
      if (pos < tag_length(t) && (uint8_t)TAG_STRING[t][pos] == (uint8_t)ch) {
        surviving |= (uint64_t)1 << (unsigned)t;
      }
    }
    if (!surviving) break;

    lexer->advance(lexer, false);
    pos++;

    enum TokenType done = TOK_CONTENT;
    unsigned done_count = 0;
    unsigned open_count = 0;
    for (enum TokenType t = TOK_TEMPLATE_OPEN; t < TOK_CONTENT; t++) {
      if (!(surviving & ((uint64_t)1 << (unsigned)t))) continue;
      if (pos == tag_length(t)) {
        done = t;
        done_count++;
      } else if (pos < tag_length(t)) {
        open_count++;
      }
    }

    if (done_count == 1 && open_count == 0) {
      *out = done;
      *consumed = pos;
      return true;
    }
    if (done_count == 0 && open_count == 0) break;

    alive = surviving;
  }

  *consumed = pos;
  return false;
}

void *tree_sitter_phue_external_scanner_create(void) { return NULL; }
void tree_sitter_phue_external_scanner_destroy(void *payload) { (void)payload; }
unsigned tree_sitter_phue_external_scanner_serialize(void *payload, char *buffer) {
  (void)payload;
  (void)buffer;
  return 0;
}
void tree_sitter_phue_external_scanner_deserialize(void *payload, const char *buffer, unsigned length) {
  (void)payload;
  (void)buffer;
  (void)length;
}

bool tree_sitter_phue_external_scanner_scan(void *payload, TSLexer *lexer, const bool *valid_symbols) {
  (void)payload;

  // Skip leading whitespace ourselves. The external scanner has lexing
  // priority over the internal lexer, so the parser's `extras` handling is
  // never given the chance to consume whitespace first. advance(..., true)
  // keeps every skipped byte out of the token range (the token starts after
  // the whitespace), exactly as tree-sitter's own docs recommend.
  while (!lexer->eof(lexer) && is_whitespace(lexer->lookahead)) {
    lexer->advance(lexer, true);
  }
  if (lexer->eof(lexer)) return false;

  enum TokenType tag;
  unsigned consumed = 0;
  if (scan_tag_run(lexer, &tag, &consumed)) {
    if (valid_symbols[tag]) {
      lexer->result_symbol = tag;
      return true;
    }
    // The tag bytes were already consumed here, but this explore-path has no
    // use for them; the caller restarts the scan from the same position for
    // the successor that can accept the tag, so dropping them is safe.
    return false;
  }

  // Proven not to be a block tag (and not whitespace — already skipped).
  // Emit the partial prefix plus the rest of the run as CONTENT, stopping at
  // the next '<' or EOF.
  lexer->result_symbol = TOK_CONTENT;
  if (consumed == 0) {
    lexer->advance(lexer, false);
  }
  while (!lexer->eof(lexer) && (char)lexer->lookahead != '<') {
    lexer->advance(lexer, false);
  }
  return true;
}
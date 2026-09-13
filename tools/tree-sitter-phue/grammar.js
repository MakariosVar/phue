/*
 * tree-sitter-phue — host grammar for the .phue single-file component format.
 *
 * The framework's PhueTemplateParser currently recognises exactly three
 * top-level blocks: <template>, <script>, <php>. A tiny external scanner
 * emits the block tags and opaque content tokens, which removes every
 * lexer/GLR ambiguity that a pure regex grammar would have. Each block's
 * interior is exposed as template_inner / script_inner / php_inner so that
 * language injection (html / javascript / php) can hand those exact byte
 * ranges to the real parsers.
 */

module.exports = grammar({
  name: 'phue',

  extras: ($) => [/\s+/, '\uFEFF'],

  externals: ($) => [
    $.template_open,
    $.template_close,
    $.script_open,
    $.script_close,
    $.php_open,
    $.php_close,
    $.content,
  ],

  conflicts: ($) => [[$.text_nodes]],

  rules: {
    source_file: ($) => repeat(choice($.template_block, $.script_block, $.php_block, $.text_nodes)),

    text_nodes: ($) => repeat1($.content),

    template_block: ($) => seq($.template_open, optional($.template_inner), $.template_close),
    template_inner: ($) => repeat1($.content),

    script_block: ($) => seq($.script_open, optional($.script_inner), $.script_close),
    script_inner: ($) => repeat1($.content),

    php_block: ($) => seq($.php_open, optional($.php_inner), $.php_close),
    php_inner: ($) => repeat1($.content),
  },
});
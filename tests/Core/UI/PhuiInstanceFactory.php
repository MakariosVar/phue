<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use PHPUnit\Framework\TestCase;

/**
 * The shared JS instance factory for Phui component logic tests.
 *
 * The Phui components are compiled to ESM modules whose only import is `vue`, and
 * `PhueComponentRuntime` strips that one import so the module has no dependencies
 * at all. What is left is an options object, and the options object is enough to
 * exercise every method that is not inherently DOM-bound: `data()`, the computed
 * getters and the methods, all bound to a plain object that behaves like an
 * instance (`this.props`, `this.$emit`, `this.$refs`, `this.$nextTick`).
 *
 * `mount()` deliberately does NOT stand in for Vue. It does not resolve `setup()`
 * (`uid` stays undefined, so no test may assert on a generated id), it does not
 * re-render, and it does not run watchers — a watcher is a scheduling behaviour of
 * the framework, not of the component. Those are asserted separately, against the
 * source.
 *
 * @package Phue\Tests\Core\UI
 */
final class PhuiInstanceFactory
{
    /**
     * Prelude source: installs `globalThis.mountPhui`.
     */
    public static function prelude(): string
    {
        return <<<'JS'
// The runner wrote the compiled component next to this file with its `vue`
// import stripped. Only the options object is needed; nothing is ever rendered.
globalThis.__COMPONENT__ = (await import("./component.mjs")).default;

globalThis.mountPhui = function (options) {
  const opts = options || {};
  const def = globalThis.__COMPONENT__;
  const props = {};

  // Prop defaults, with Vue's own rule: a function default is a FACTORY for a
  // complex type (Array/Object) and a plain value for everything else.
  const declared = def.props || {};
  for (const key of Object.keys(declared)) {
    const spec = declared[key] || {};
    const types = [].concat(spec.type || []);
    const complex = types.some((t) => t === Array || t === Object);

    if (!('default' in spec)) {
      props[key] = undefined;
    } else if (complex && typeof spec.default === 'function') {
      props[key] = spec.default();
    } else {
      props[key] = spec.default;
    }
  }

  Object.assign(props, opts.props || {});

  const vm = {};
  for (const key of Object.keys(props)) {
    Object.defineProperty(vm, key, {
      get: () => props[key],
      set: (value) => { props[key] = value; },
      enumerable: true,
      configurable: true
    });
  }

  const emitted = [];
  vm.$emit = (event, ...args) => { emitted.push({ event, args }); };
  vm.$nextTick = (fn) => { if (typeof fn === 'function') fn(); };
  vm.$refs = opts.refs || {};
  vm.$el = opts.el || null;
  vm.__emitted = emitted;
  vm.__props = props;

  if (def.computed) {
    for (const name of Object.keys(def.computed)) {
      Object.defineProperty(vm, name, {
        get: () => def.computed[name].call(vm),
        enumerable: true,
        configurable: true
      });
    }
  }

  if (def.methods) {
    for (const name of Object.keys(def.methods)) {
      vm[name] = def.methods[name].bind(vm);
    }
  }

  if (def.data) {
    Object.assign(vm, def.data.call(vm));
  }

  return vm;
};

/**
 * Fires one declared watcher, so a prop-change-wins rule is testable.
 *
 * `mountPhui` deliberately does not run watchers — they are framework scheduling,
 * not component behaviour — but the HANDLER is the component's own code, and a
 * contract like "a prop change wins over the internal state" is only provable by
 * calling it.
 */
globalThis.runPhuiWatcher = function (vm, name, value) {
  const declared = globalThis.__COMPONENT__.watch || {};
  const spec = declared[name];
  if (!spec) {
    throw new Error('no watcher named ' + name);
  }

  const handler = typeof spec === 'function' ? spec : spec.handler;
  if (typeof handler !== 'function') {
    throw new Error('watcher ' + name + ' has no handler');
  }

  handler.call(vm, value, vm[name]);
};

/** A collector, so each scenario reports a named pass/fail instead of throwing. */
globalThis.__scenarioResults = [];
globalThis.scenario = function (name, fn) {
  try {
    const extra = fn();
    globalThis.__scenarioResults.push({ name, pass: true, extra: extra === undefined ? null : String(extra) });
  } catch (error) {
    globalThis.__scenarioResults.push({ name, pass: false, extra: String((error && error.message) || error) });
  }
};

globalThis.assertEqual = function (actual, expected, note) {
  if (actual !== expected) {
    throw new Error((note ? note + ': ' : '') + 'expected ' + JSON.stringify(expected) + ' but got ' + JSON.stringify(actual));
  }
};

globalThis.assertTrue = function (value, note) {
  if (value !== true) {
    throw new Error((note ? note + ': ' : '') + 'expected true but got ' + JSON.stringify(value));
  }
};

globalThis.assertFalse = function (value, note) {
  if (value !== false) {
    throw new Error((note ? note + ': ' : '') + 'expected false but got ' + JSON.stringify(value));
  }
};

globalThis.assertContains = function (haystack, needle, note) {
  if (String(haystack).indexOf(needle) === -1) {
    throw new Error((note ? note + ': ' : '') + JSON.stringify(needle) + ' not found in ' + JSON.stringify(haystack));
  }
};

globalThis.assertOmits = function (haystack, needle, note) {
  if (String(haystack).toLowerCase().indexOf(String(needle).toLowerCase()) !== -1) {
    throw new Error((note ? note + ': ' : '') + JSON.stringify(needle) + ' unexpectedly present in ' + JSON.stringify(haystack));
  }
};

/** Prints the single JSON document the PHP side decodes. */
globalThis.__emitResults = function () {
  console.log(JSON.stringify({ results: globalThis.__scenarioResults }));
};
JS;
    }

    /**
     * Wraps scenarios and emits the result document.
     *
     * @param string $scenarios JS statements, each calling `scenario(...)`
     */
    public static function source(string $scenarios): string
    {
        return $scenarios . "\n\nglobalThis.__emitResults();\n";
    }
}
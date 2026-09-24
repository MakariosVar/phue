import { test, expect } from 'bun:test';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { Registry, parseRawGrammar, INITIAL } from 'vscode-textmate';
import { loadWASM, createOnigScanner, createOnigString } from 'vscode-oniguruma';

const toolsDir = path.join(import.meta.dir, '..');
const grammarPath = path.join(toolsDir, 'syntaxes/phue.tmLanguage.json');
const htmlFixturePath = path.join(toolsDir, 'tests', 'fixtures', 'html.tmLanguage.json');
const jsFixturePath = path.join(toolsDir, 'tests', 'fixtures', 'javascript.tmLanguage.json');
const customDataPath = path.join(toolsDir, 'custom-data/phui-custom-data.json');

async function loadGrammar(): Promise<import('vscode-textmate').IGrammar> {
  const wasmPath = path.join(path.dirname(require.resolve('vscode-oniguruma')), 'onig.wasm');
  await loadWASM(fs.readFileSync(wasmPath));
  const grammarSource = fs.readFileSync(grammarPath, 'utf8');
  const raw = parseRawGrammar(grammarSource, grammarPath);
  const htmlFixtureSource = fs.readFileSync(htmlFixturePath, 'utf8');
  const rawHtml = parseRawGrammar(htmlFixtureSource, htmlFixturePath);
  const jsFixtureSource = fs.readFileSync(jsFixturePath, 'utf8');
  const rawJs = parseRawGrammar(jsFixtureSource, jsFixturePath);
  const registry = new Registry({
    onigLib: Promise.resolve({ createOnigScanner, createOnigString }),
    loadGrammar: async (scopeName) => {
      if (scopeName === 'source.phue') return raw;
      if (scopeName === 'text.html.basic') return rawHtml;
      if (scopeName === 'source.js') return rawJs;
      return null;
    },
  });
  const grammar = await registry.loadGrammar('source.phue');
  if (!grammar) throw new Error('Grammar failed to load');
  return grammar;
}

/**
 * Tokenize `line` and return { scopes: all scopes seen, tokenAt: (index) => string[] }.
 */
function tokenize(grammar: import('vscode-textmate').IGrammar, line: string, ruleStack = INITIAL) {
  const result = grammar.tokenizeLine(line, ruleStack);
  const seen = new Set<string>();
  for (const token of result.tokens) {
    for (const scope of token.scopes) seen.add(scope);
  }
  return { result, seen, tokenAt: (index: number) => result.tokens.find((t) => t.startIndex <= index && index < t.endIndex)?.scopes ?? [] };
}

test('grammar loads and assigns block tag scopes', async () => {
  const g = await loadGrammar();
  const { seen, tokenAt } = tokenize(g, '<template>');
  expect(seen.has('entity.name.tag.phue')).toBe(true);
  expect(tokenAt(0).some((s) => s === 'punctuation.definition.tag.begin.phue')).toBe(true);
  expect(tokenAt(1).some((s) => s === 'entity.name.tag.phue')).toBe(true);
  const php = tokenize(g, '<php>');
  expect(
    php.result.tokens.some((t) => t.scopes.includes('punctuation.definition.tag.attributes.phue'))
  ).toBe(true);
});

test('template content gets Vue/HTML scope', async () => {
  const g = await loadGrammar();
  let stack = INITIAL;
  stack = tokenize(g, '<template>', stack).result.ruleStack;
  const { seen } = tokenize(g, '  <div class="d-flex">Hello {{ name }}</div>', stack);
  expect(seen.has('text.html.vue')).toBe(true);
  expect(seen.has('meta.embedded.block.template.phue')).toBe(true);
  // the <php> block must NOT leak into template content
  expect(seen.has('source.php')).toBe(false);
  // text.html.basic (official HTML grammar) must be wired in
  expect(seen.has('entity.name.tag.html')).toBe(true);
  // {{ }} interpolation gets its Vue scope
  expect(seen.has('meta.expression.vue')).toBe(true);
});

test('script content gets official JavaScript scopes', async () => {
  const g = await loadGrammar();
  let stack = INITIAL;
  const lines = ['<script>', '  export default {', '    setup() {', "      const x = ref('v');", '    }', '  };', '</script>'];
  const scopesOnJsLine: string[] = [];
  let exportScopes: string[] = [];
  for (const line of lines) {
    const { result, seen } = tokenize(g, line, stack);
    stack = result.ruleStack;
    if (line.includes('ref(')) {
      scopesOnJsLine.push(...seen);
      expect(seen.has('source.js')).toBe(true);
      expect(seen.has('meta.embedded.block.script.phue')).toBe(true);
    }
    if (line.includes('export')) exportScopes = [...seen];
  }
  expect(exportScopes.includes('keyword.control.export.js')).toBe(true);
});

test('php block highlights keywords, variables and strings without <?php tag', async () => {
  const g = await loadGrammar();
  let stack = INITIAL;
  const lines = [
    '<php>',
    '  $config = [',
    "      'features' => ['lazy', 'ssr'],",
    '  ];',
    '',
    '  return [',
    "      'title' => date('c'),",
    "      'state' => ['user' => ['registeredAt' => '2026-09-13']],",
    '  ];',
    '</php>',
  ];
  const tokens: { line: string; scopes: string[] }[] = [];
  for (const line of lines) {
    const r = g.tokenizeLine(line, stack);
    stack = r.ruleStack;
    tokens.push({ line, scopes: r.tokens.flatMap((t) => t.scopes) });
  }

  const phpContent = tokens.slice(1, -1);
  expect(phpContent.length).toBeGreaterThan(0);
  for (const t of phpContent) {
    expect(t.scopes).toContain('source.php');
  }
  const all = tokens.flatMap((t) => t.scopes);
  expect(all).toContain('keyword.control.php'); // return
  expect(all).toContain('variable.other.php'); // $config
  expect(all).toContain('string.quoted.single.php'); // 'features', 'title', ...
  expect(all).toContain('entity.name.function.php'); // date()
});

test('php comment lines get comment scope', async () => {
  const g = await loadGrammar();
  let stack = INITIAL;
  stack = tokenize(g, '<php>', stack).result.ruleStack;
  const { seen } = tokenize(g, '  // a note about this block', stack);
  expect(seen.has('comment.line.php.phue')).toBe(true);
});

test('full SFC round-trip has no unstyled tag leaks', async () => {
  const g = await loadGrammar();
  const source = fs.readFileSync(path.join(toolsDir, '..', 'fixtures', 'page.phue'), 'utf8');
  let stack = INITIAL;
  let sawPhpBlock = false;
  let phpContentVerified = false;
  for (const line of source.split('\n')) {
    const r = g.tokenizeLine(line, stack);
    const scopes = r.tokens.flatMap((t) => t.scopes);
    expect(scopes.some((s) => s === 'invalid.illegal.phue')).toBe(false);
    if (line.includes('<php>')) {
      expect(scopes.some((s) => s === 'source.php')).toBe(false);
      sawPhpBlock = true;
    } else if (sawPhpBlock && !line.includes('</php>')) {
      expect(scopes).toContain('source.php');
      expect(scopes).toContain('meta.embedded.block.php.phue');
      phpContentVerified = true;
    }
    stack = r.ruleStack;
  }
  expect(phpContentVerified).toBe(true);
});

test('nested template tags are highlighted correctly (slot templates)', async () => {
  const g = await loadGrammar();

const source = [
    '<template>',
    '  <example-factory>',
    '    <template #demo>',
    '      <div>',
    '        <ph-input label="Name" placeholder="Enter your name" :value="name" @update:model-value="name = $event" />',
    '      </div>',
    '    </template>',
    '  </example-factory>',
    '</template>',
  ];

  let stack = INITIAL;
  let inOuterTemplate = false;
  let inInnerTemplate = false;
  let outerTemplateClosed = false;
  let innerTemplateClosed = false;

  for (const line of source) {
    const r = g.tokenizeLine(line, stack);
    const scopes = r.tokens.flatMap((t) => t.scopes);
    stack = r.ruleStack;

    // Track outer template
    if (line.includes('<template>') && !line.includes('#demo')) {
      inOuterTemplate = true;
      expect(scopes.some((s) => s === 'entity.name.tag.phue')).toBe(true);
      expect(scopes.some((s) => s === 'punctuation.definition.tag.begin.phue')).toBe(true);
    }

    // Track inner template (slot)
    if (line.includes('<template #demo>')) {
      inInnerTemplate = true;
      // Inner template should be handled by HTML grammar, not as a Phue block
      expect(scopes.some((s) => s === 'entity.name.tag.html')).toBe(true);
    }

    // Content inside outer template should have Vue/HTML scope
    if (inOuterTemplate && !inInnerTemplate && line.includes('<example-factory>')) {
      expect(scopes.some((s) => s === 'text.html.vue')).toBe(true);
      expect(scopes.some((s) => s === 'meta.embedded.block.template.phue')).toBe(true);
    }

    // Content inside inner template should also have Vue/HTML scope (via HTML grammar)
    if (inInnerTemplate && line.includes('<div>')) {
      expect(scopes.some((s) => s === 'text.html.vue')).toBe(true);
    }

    // Track closing of inner template
    if (inInnerTemplate && line.includes('</template>') && !outerTemplateClosed) {
      innerTemplateClosed = true;
      inInnerTemplate = false;
      // Inner closing tag should be HTML grammar
      expect(scopes.some((s) => s === 'entity.name.tag.html')).toBe(true);
    }

    // Track closing of outer template
    if (inOuterTemplate && !inInnerTemplate && line === '</template>' && innerTemplateClosed) {
      outerTemplateClosed = true;
      inOuterTemplate = false;
      expect(scopes.some((s) => s === 'entity.name.tag.phue')).toBe(true);
      expect(scopes.some((s) => s === 'punctuation.definition.tag.end.phue')).toBe(true);
    }
  }

  expect(innerTemplateClosed).toBe(true);
  expect(outerTemplateClosed).toBe(true);
});

test('template with multiple nested slot templates', async () => {
  const g = await loadGrammar();

  const source = [
    '<template>',
    '  <ph-card>',
    '    <template #header>',
    '      <ph-heading :level="3">Card Title</ph-heading>',
    '    </template>',
    '    <template #footer>',
    '      <ph-button variant="primary">Action</ph-button>',
    '    </template>',
    '  </ph-card>',
    '</template>',
  ];

  let stack = INITIAL;
  let inOuterTemplate = false;
  let slotCount = 0;

  for (const line of source) {
    const r = g.tokenizeLine(line, stack);
    const scopes = r.tokens.flatMap((t) => t.scopes);
    stack = r.ruleStack;

    if (line === '<template>') {
      inOuterTemplate = true;
      expect(scopes.some((s) => s === 'entity.name.tag.phue')).toBe(true);
    }

    if (line.includes('<template #')) {
      slotCount++;
      // Slot templates should be handled by HTML grammar
      expect(scopes.some((s) => s === 'entity.name.tag.html')).toBe(true);
    }

    if (inOuterTemplate && line.includes('<ph-card>')) {
      expect(scopes.some((s) => s === 'text.html.vue')).toBe(true);
      expect(scopes.some((s) => s === 'meta.embedded.block.template.phue')).toBe(true);
    }

    if (line.trim() === '</template>' && inOuterTemplate) {
      if (slotCount > 0) {
        slotCount--;
        expect(scopes.some((s) => s === 'entity.name.tag.html')).toBe(true);
      } else {
        expect(scopes.some((s) => s === 'entity.name.tag.phue')).toBe(true);
        expect(scopes.some((s) => s === 'punctuation.definition.tag.end.phue')).toBe(true);
        inOuterTemplate = false;
      }
    }
  }

  expect(slotCount).toBe(0);
  expect(inOuterTemplate).toBe(false);
});

test('custom data is valid v1.1 and covers every PhUI component file', () => {
  const data = JSON.parse(fs.readFileSync(customDataPath, 'utf8'));
  expect(data.version).toBe(1.1);
  expect(Array.isArray(data.tags)).toBe(true);
  const names = new Set(data.tags.map((t: { name: string }) => t.name));

  const uiDir = path.join(toolsDir, '..', '..', 'src', 'Core', 'UI', 'Phui');
  const shipped = fs
    .readdirSync(uiDir)
    .filter((f) => f.endsWith('.phue'))
    .map((f) => f.replace(/\.phue$/, '').replace(/(?<!^)[A-Z]/g, (c) => `-${c}`).toLowerCase());
  for (const tag of shipped) {
    expect(names.has(tag)).toBe(true);
  }

  for (const tag of data.tags) {
    expect(typeof tag.name).toBe('string');
    expect(tag.name.length).toBeGreaterThan(0);
    if (tag.attributes) {
      for (const attr of tag.attributes) {
        expect(typeof attr.name).toBe('string');
        if (attr.values) {
          expect(attr.values).toEqual(expect.arrayContaining(['true', 'false']));
        }
      }
    }
  }
});
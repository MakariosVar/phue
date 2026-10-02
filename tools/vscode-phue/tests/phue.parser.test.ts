import { test, expect } from 'bun:test';
import { parsePhueSfc, hasPhpBlock } from '../src/parser';

test('parsePhueSfc parses a standard 3-section .phue SFC', () => {
  const code = `
<php>
$title = 'Home';
return ['title' => $title];
</php>

<template>
  <div>
    <h1>{{ title }}</h1>
    <ph-button variant="primary">Click</ph-button>
  </div>
</template>

<script>
export default { setup() {} };
</script>
`;

  const parsed = parsePhueSfc(code);
  expect(parsed.phpBlocks.length).toBe(1);
  expect(parsed.templateBlock).toBeDefined();
  expect(parsed.scriptBlocks.length).toBe(1);
  expect(parsed.validationErrors.length).toBe(0);

  expect(parsed.phpBlocks[0].content).toContain("$title = 'Home';");
  expect(parsed.templateBlock?.content).toContain('<ph-button');
  expect(parsed.scriptBlocks[0].content).toContain('export default');
});

test('parsePhueSfc flags multiple <php> blocks as errors', () => {
  const code = `
<php>
$title = 'One';
return ['title' => $title];
</php>

<template>
  <div>Test</div>
</template>

<php>
$title = 'Two';
return ['title' => $title];
</php>

<php>
$title = 'Three';
return ['title' => $title];
</php>
`;

  const parsed = parsePhueSfc(code);
  expect(parsed.phpBlocks.length).toBe(3);
  expect(parsed.validationErrors.length).toBe(2);

  const errors = parsed.validationErrors.filter((e) => e.code === 'phue.duplicate-php-block');
  expect(errors.length).toBe(2);
  expect(errors[0].message).toContain('Only one <php> block is allowed per .phue file. Found 3 <php> blocks.');
  expect(errors[1].message).toContain('Only one <php> block is allowed per .phue file. Found 3 <php> blocks.');
});

test('parsePhueSfc allows 0 <php> blocks for client-only / static SFCs', () => {
  const code = `
<template>
  <span>Static icon</span>
</template>
`;
  const parsed = parsePhueSfc(code);
  expect(parsed.phpBlocks.length).toBe(0);
  expect(parsed.validationErrors.length).toBe(0);
});

test('parsePhueSfc correctly handles nested <template> slot tags', () => {
  const code = `
<template>
  <ph-card>
    <template #header>
      <h3>Header</h3>
    </template>
    <template #footer>
      <button>OK</button>
    </template>
  </ph-card>
</template>
`;

  const parsed = parsePhueSfc(code);
  expect(parsed.templateBlock).toBeDefined();
  expect(parsed.templateBlock?.closed).toBe(true);
  expect(parsed.templateBlock?.content).toContain('<template #header>');
  expect(parsed.templateBlock?.content).toContain('<template #footer>');
  expect(parsed.validationErrors.length).toBe(0);
});

test('hasPhpBlock detects presence of <php> tag', () => {
  expect(hasPhpBlock('<php> return []; </php>')).toBe(true);
  expect(hasPhpBlock('  <php>\n$x = 1;\n</php>')).toBe(true);
  expect(hasPhpBlock('<template><div></div></template>')).toBe(false);
  expect(hasPhpBlock('<ph-button>Click</ph-button>')).toBe(false);
});

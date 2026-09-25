import { test, expect } from 'bun:test';
import {
  detectBlock,
  getAttributePrefix,
  getOpenTagName,
  getTagNamePrefix,
  inNamePosition,
  insideQuotedValue,
} from '../src/context';

test('detectBlock tracks the innermost open block', () => {
  expect(detectBlock('')).toBe('none');
  expect(detectBlock('<template>')).toBe('template');
  expect(detectBlock('<template>\n  <div>x</div>')).toBe('template');
  expect(detectBlock('<template></template>')).toBe('none');
  expect(detectBlock('<template></template>\n<script>\n  const a = 1')).toBe('script');
  expect(detectBlock('<template></template>\n<php>\n  $x = 1;')).toBe('php');
  expect(detectBlock('<template></template>\n<script>\n</script>\n')).toBe('none');
  expect(detectBlock('<template></template>\n<script>\n</script>\n<php>\n$y = 2;\n</php>\n')).toBe('none');
  expect(detectBlock('before text <script> import x from "y"; ')).toBe('script');
});

test('detectBlock handles nested template tags', () => {
  // Nested <template> (Vue fragments) should not close the outer block
  expect(detectBlock('<template>\n  <template v-for="x in xs">\n    <div></div>\n  </template>')).toBe('template');
  expect(detectBlock('<template>\n  <template v-if="cond">\n    <span></span>\n  </template>\n</template>')).toBe('none');
  expect(detectBlock('<template>\n  <template #header>\n    <ph-heading />\n  </template>\n</template>')).toBe('none');
});

test('inNamePosition flags only start-tag name slots', () => {
  expect(inNamePosition('<')).toBe(true);
  expect(inNamePosition('< ')).toBe(true);
  expect(inNamePosition('<ph')).toBe(true);
  expect(inNamePosition('<ph-card')).toBe(true);
  expect(inNamePosition('<ph-card ')).toBe(false);
  expect(inNamePosition('<div class="x"')).toBe(false);
  expect(inNamePosition('</div>')).toBe(false);
  expect(inNamePosition('  </div>')).toBe(false);
  expect(inNamePosition('plain text')).toBe(false);
  expect(inNamePosition('<!-- note')).toBe(false);
});

test('getTagNamePrefix returns the partial tag name', () => {
  expect(getTagNamePrefix('<')).toBe('');
  expect(getTagNamePrefix('<ph')).toBe('ph');
  expect(getTagNamePrefix('<ph-card')).toBe('ph-card');
  expect(getTagNamePrefix('<div></di')).toBe('');
});

test('getOpenTagName returns current open start tag', () => {
  expect(getOpenTagName('<div')).toBe('div');
  expect(getOpenTagName('<ph-card ')).toBe('ph-card');
  expect(getOpenTagName('  <button type="submit" ')).toBe('button');
  expect(getOpenTagName('</div>')).toBeUndefined();
  expect(getOpenTagName('nothing here')).toBeUndefined();
});

test('getAttributePrefix reads the typed attribute fragment', () => {
  expect(getAttributePrefix('<ph-card ')).toBe('');
  expect(getAttributePrefix('<ph-card d')).toBe('d');
  expect(getAttributePrefix('<ph-card :di')).toBe(':di');
});

test('insideQuotedValue detects being inside an attribute value', () => {
  expect(insideQuotedValue('<ph-card :disabled="loadi')).toBe(true);
  expect(insideQuotedValue('<ph-card :disabled="loading"')).toBe(false);
  expect(insideQuotedValue('<ph-card ')).toBe(false);
});
export type PhueBlock = 'template' | 'script' | 'style' | 'php' | 'none';

const BLOCK_RE = /<(template|script|style|php)(?=[\s/>])|<\/(template|script|style|php)>/gi;

/**
 * Tracks the innermost open block ('template' | 'script' | 'style' | 'php' | 'none')
 * up to the given text offset. Handles nested <template> tags accurately.
 */
export function detectBlock(textBefore: string): PhueBlock {
  const stack: PhueBlock[] = [];
  let m: RegExpExecArray | null;
  BLOCK_RE.lastIndex = 0;
  while ((m = BLOCK_RE.exec(textBefore)) !== null) {
    if (m[0][1] === '/') {
      const closingTag = (m[2] || m[1]).toLowerCase() as PhueBlock;
      // Pop matching tag from stack
      const idx = stack.lastIndexOf(closingTag);
      if (idx !== -1) {
        stack.splice(idx, 1);
      } else {
        stack.pop();
      }
    } else {
      const openTag = (m[1] || m[2]).toLowerCase() as PhueBlock;
      stack.push(openTag);
    }
  }
  return stack.length > 0 ? stack[stack.length - 1] : 'none';
}

/**
 * Checks if cursor is in a start-tag name position (e.g. `<ph` or `< ` or `<`).
 */
export function inNamePosition(lineBefore: string): boolean {
  const lastLess = lineBefore.lastIndexOf('<');
  const lastGreater = lineBefore.lastIndexOf('>');
  if (lastLess === -1 || lastLess < lastGreater) return false;
  const slice = lineBefore.slice(lastLess);
  if (slice.startsWith('</') || slice.startsWith('<!--')) return false;
  return /^<\s*([A-Za-z][\w.-]*)?$/.test(slice);
}

/**
 * Returns partial tag name being typed (e.g. `<ph-card` -> `ph-card`).
 */
export function getTagNamePrefix(lineBefore: string): string {
  if (!inNamePosition(lineBefore)) return '';
  const slice = lineBefore.slice(lineBefore.lastIndexOf('<'));
  const m = slice.match(/^<\s*([A-Za-z][\w.-]*)?$/);
  return m?.[1] ?? '';
}

/**
 * Returns the currently open start tag name on this line or nearby.
 */
export function getOpenTagName(lineBefore: string): string | undefined {
  const lastLess = lineBefore.lastIndexOf('<');
  const lastGreater = lineBefore.lastIndexOf('>');
  if (lastLess === -1 || lastLess < lastGreater) return undefined;
  const slice = lineBefore.slice(lastLess);
  if (slice.startsWith('</') || slice.startsWith('<!--')) return undefined;
  const m = slice.match(/^<\s*([A-Za-z][\w.-]*)/);
  return m?.[1];
}

/**
 * Returns attribute prefix currently being typed (e.g. `:var` or `@cli` or `#hea`).
 */
export function getAttributePrefix(lineBefore: string): string {
  const m = lineBefore.match(/\s([:@#]?[\w.:-]*)$/);
  return m?.[1] ?? '';
}

/**
 * Detects if cursor is inside a quoted attribute value ("..." or '...').
 */
export function insideQuotedValue(lineBefore: string): boolean {
  const lastLess = lineBefore.lastIndexOf('<');
  if (lastLess === -1) return false;
  const slice = lineBefore.slice(lastLess);
  const dq = (slice.match(/"/g) ?? []).length;
  const sq = (slice.match(/'/g) ?? []).length;
  return dq % 2 === 1 || sq % 2 === 1;
}

/**
 * Checks if cursor is inside a Vue slot attribute position (e.g. `<template #` or `v-slot:`).
 */
export function inSlotPosition(lineBefore: string): boolean {
  return /#[\w-]*$/.test(lineBefore) || /v-slot:[\w-]*$/.test(lineBefore);
}
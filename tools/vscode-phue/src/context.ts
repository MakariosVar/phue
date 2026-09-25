export type PhueBlock = 'template' | 'script' | 'php' | 'none';

const BLOCK_RE = /<(template|script|php)(?=[\s/>])|<\/(template|script|php)>/g;

export function detectBlock(textBefore: string): PhueBlock {
  const stack: PhueBlock[] = [];
  let m: RegExpExecArray | null;
  BLOCK_RE.lastIndex = 0;
  while ((m = BLOCK_RE.exec(textBefore)) !== null) {
    if (m[0][1] === '/') {
      stack.pop();
    } else {
      stack.push(m[1] as PhueBlock);
    }
  }
  return stack.length > 0 ? stack[stack.length - 1] : 'none';
}

export function inNamePosition(lineBefore: string): boolean {
  const lastLess = lineBefore.lastIndexOf('<');
  const lastGreater = lineBefore.lastIndexOf('>');
  if (lastLess === -1 || lastLess < lastGreater) return false;
  const slice = lineBefore.slice(lastLess);
  if (slice.startsWith('</')) return false;
  return /^<\s*([A-Za-z][\w.-]*)?$/.test(slice);
}

export function getTagNamePrefix(lineBefore: string): string {
  if (!inNamePosition(lineBefore)) return '';
  const slice = lineBefore.slice(lineBefore.lastIndexOf('<'));
  const m = slice.match(/^<\s*([A-Za-z][\w.-]*)?$/);
  return m?.[1] ?? '';
}

export function getOpenTagName(lineBefore: string): string | undefined {
  const lastLess = lineBefore.lastIndexOf('<');
  const lastGreater = lineBefore.lastIndexOf('>');
  if (lastLess === -1 || lastLess < lastGreater) return undefined;
  const slice = lineBefore.slice(lastLess);
  if (slice.startsWith('</')) return undefined;
  const m = slice.match(/^<\s*([A-Za-z][\w.-]*)/);
  return m?.[1];
}

export function getAttributePrefix(lineBefore: string): string {
  const m = lineBefore.match(/\s([:@]?[\w.:-]*)$/);
  return m?.[1] ?? '';
}

export function insideQuotedValue(lineBefore: string): boolean {
  const lastLess = lineBefore.lastIndexOf('<');
  if (lastLess === -1) return false;
  const slice = lineBefore.slice(lastLess);
  const dq = (slice.match(/"/g) ?? []).length;
  const sq = (slice.match(/'/g) ?? []).length;
  return dq % 2 === 1 || sq % 2 === 1;
}
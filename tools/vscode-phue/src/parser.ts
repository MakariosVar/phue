/**
 * Phue Single File Component (SFC) Parser
 *
 * Accurately parses top-level <php>, <template>, <script>, and <style> sections.
 * Enforces the framework rule: AT MOST ONE <php> block per .phue component.
 */

export interface SfcBlock {
  type: 'php' | 'template' | 'script' | 'style';
  startTagOffset: number;
  startTagEndOffset: number;
  contentOffset: number;
  contentEndOffset: number;
  endTagOffset: number;
  endTagEndOffset: number;
  content: string;
  attributes: Record<string, string>;
  startLine: number;
  endLine: number;
  closed: boolean;
}

export interface SfcValidationError {
  code: 'phue.duplicate-php-block' | 'phue.unclosed-block';
  message: string;
  startOffset: number;
  endOffset: number;
  line: number;
  severity: 'error' | 'warning';
}

export interface ParsedSfc {
  phpBlocks: SfcBlock[];
  templateBlock?: SfcBlock;
  scriptBlocks: SfcBlock[];
  styleBlocks: SfcBlock[];
  allBlocks: SfcBlock[];
  validationErrors: SfcValidationError[];
}

function parseAttributes(attrText: string): Record<string, string> {
  const attrs: Record<string, string> = {};
  const re = /([:@#\w.-]+)(?:=(?:"([^"]*)"|'([^']*)'|(\S+)))?/g;
  let match: RegExpExecArray | null;
  while ((match = re.exec(attrText)) !== null) {
    const key = match[1];
    const val = match[2] ?? match[3] ?? match[4] ?? '';
    attrs[key] = val;
  }
  return attrs;
}

function getLineNumber(text: string, offset: number): number {
  let line = 0;
  for (let i = 0; i < offset && i < text.length; i++) {
    if (text[i] === '\n') line++;
  }
  return line;
}

/**
 * Parses a .phue file into its top-level SFC blocks.
 */
export function parsePhueSfc(text: string): ParsedSfc {
  const result: ParsedSfc = {
    phpBlocks: [],
    scriptBlocks: [],
    styleBlocks: [],
    allBlocks: [],
    validationErrors: [],
  };

  const blockStartRe = /<([A-Za-z][\w.-]*)([\s\S]*?)>/g;
  let match: RegExpExecArray | null;

  while ((match = blockStartRe.exec(text)) !== null) {
    const rawTag = match[1].toLowerCase();
    if (rawTag !== 'php' && rawTag !== 'template' && rawTag !== 'script' && rawTag !== 'style') {
      continue;
    }

    const type = rawTag as SfcBlock['type'];
    const startTagOffset = match.index;
    const startTagEndOffset = match.index + match[0].length;
    const attrText = match[2] || '';
    const attributes = parseAttributes(attrText);
    const startLine = getLineNumber(text, startTagOffset);

    // Self-closing top-level block
    if (match[0].endsWith('/>')) {
      const block: SfcBlock = {
        type,
        startTagOffset,
        startTagEndOffset,
        contentOffset: startTagEndOffset,
        contentEndOffset: startTagEndOffset,
        endTagOffset: startTagOffset,
        endTagEndOffset: startTagEndOffset,
        content: '',
        attributes,
        startLine,
        endLine: startLine,
        closed: true,
      };
      registerBlock(result, block);
      continue;
    }

    // Find matching closing tag
    let contentEndOffset = -1;
    let endTagOffset = -1;
    let endTagEndOffset = -1;
    let closed = false;

    if (type === 'template') {
      // <template> can contain nested <template #slot> tags!
      // Must track nesting depth:
      let depth = 1;
      const tagRe = /<\/?template\b[^>]*>/gi;
      tagRe.lastIndex = startTagEndOffset;
      let tm: RegExpExecArray | null;
      while ((tm = tagRe.exec(text)) !== null) {
        if (tm[0].startsWith('</')) {
          depth--;
          if (depth === 0) {
            contentEndOffset = tm.index;
            endTagOffset = tm.index;
            endTagEndOffset = tm.index + tm[0].length;
            closed = true;
            blockStartRe.lastIndex = endTagEndOffset;
            break;
          }
        } else if (!tm[0].endsWith('/>')) {
          depth++;
        }
      }
    } else {
      const closeRe = new RegExp(`</${type}\\s*>`, 'gi');
      closeRe.lastIndex = startTagEndOffset;
      const cm = closeRe.exec(text);
      if (cm) {
        contentEndOffset = cm.index;
        endTagOffset = cm.index;
        endTagEndOffset = cm.index + cm[0].length;
        closed = true;
        blockStartRe.lastIndex = endTagEndOffset;
      }
    }

    if (!closed) {
      contentEndOffset = text.length;
      endTagOffset = text.length;
      endTagEndOffset = text.length;
      result.validationErrors.push({
        code: 'phue.unclosed-block',
        message: `Unclosed <${type}> block in .phue component.`,
        startOffset: startTagOffset,
        endOffset: startTagEndOffset,
        line: startLine,
        severity: 'error',
      });
    }

    const content = text.slice(startTagEndOffset, contentEndOffset);
    const endLine = getLineNumber(text, endTagEndOffset);

    const block: SfcBlock = {
      type,
      startTagOffset,
      startTagEndOffset,
      contentOffset: startTagEndOffset,
      contentEndOffset,
      endTagOffset,
      endTagEndOffset,
      content,
      attributes,
      startLine,
      endLine,
      closed,
    };

    registerBlock(result, block);
  }

  // Validate the AT MOST ONE <php> block rule
  if (result.phpBlocks.length > 1) {
    for (let i = 1; i < result.phpBlocks.length; i++) {
      const dup = result.phpBlocks[i];
      result.validationErrors.push({
        code: 'phue.duplicate-php-block',
        message: `Only one <php> block is allowed per .phue file. Found ${result.phpBlocks.length} <php> blocks.`,
        startOffset: dup.startTagOffset,
        endOffset: dup.startTagEndOffset,
        line: dup.startLine,
        severity: 'error',
      });
    }
  }

  return result;
}

function registerBlock(result: ParsedSfc, block: SfcBlock): void {
  result.allBlocks.push(block);
  switch (block.type) {
    case 'php':
      result.phpBlocks.push(block);
      break;
    case 'template':
      if (!result.templateBlock) {
        result.templateBlock = block;
      }
      break;
    case 'script':
      result.scriptBlocks.push(block);
      break;
    case 'style':
      result.styleBlocks.push(block);
      break;
  }
}

/**
 * Checks if the document already has at least one <php> block.
 */
export function hasPhpBlock(text: string): boolean {
  return /<php(?=[\s/>])/i.test(text);
}

/**
 * Returns the primary <php> block if present.
 */
export function getPrimaryPhpBlock(parsed: ParsedSfc): SfcBlock | undefined {
  return parsed.phpBlocks[0];
}

/**
 * Finds which SFC block contains the given character offset.
 */
export function findBlockAtOffset(parsed: ParsedSfc, offset: number): SfcBlock | undefined {
  for (const block of parsed.allBlocks) {
    if (offset >= block.startTagOffset && offset <= block.endTagEndOffset) {
      return block;
    }
  }
  return undefined;
}

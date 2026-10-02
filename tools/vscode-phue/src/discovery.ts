import * as fs from 'node:fs';
import * as path from 'node:path';

export interface ComponentProp {
  name: string;
  type?: string;
  default?: string;
  description?: string;
  values?: string[];
}

export interface ComponentSlot {
  name: string;
  description?: string;
}

export interface ComponentEmit {
  name: string;
  description?: string;
}

export interface DiscoveredComponent {
  pascalName: string;
  kebabName: string;
  filePath: string;
  description?: string;
  props: ComponentProp[];
  slots: ComponentSlot[];
  emits: ComponentEmit[];
  isBuiltin: boolean;
}

export function toPascalCase(name: string): string {
  return name
    .replace(/\.phue$/i, '')
    .replace(/(?:^|[-_])(\w)/g, (_, c) => c.toUpperCase());
}

export function toKebabCase(name: string): string {
  return name
    .replace(/\.phue$/i, '')
    .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
    .toLowerCase();
}

/**
 * Extracts props, slots, emits from .phue SFC text.
 */
export function extractComponentMetadata(
  content: string,
  filePath: string,
  customData?: { tags?: Array<{ name: string; description?: string; attributes?: Array<{ name: string; description?: string; values?: Array<{ name: string }> }> }> },
): DiscoveredComponent {
  const baseName = path.basename(filePath, '.phue');
  const pascalName = toPascalCase(baseName);
  const kebabName = toKebabCase(baseName);

  const props: ComponentProp[] = [];
  const slots: ComponentSlot[] = [];
  const emits: ComponentEmit[] = [];
  let description = '';

  // Check custom data catalog first for official framework components
  if (customData?.tags) {
    const customTag = customData.tags.find(
      (t) => t.name.toLowerCase() === kebabName || t.name.toLowerCase() === pascalName.toLowerCase(),
    );
    if (customTag) {
      if (customTag.description) description = customTag.description;
      if (customTag.attributes) {
        for (const attr of customTag.attributes) {
          if (attr.name.startsWith('@')) {
            emits.push({ name: attr.name.slice(1), description: attr.description });
          } else {
            const propName = attr.name.startsWith(':') ? attr.name.slice(1) : attr.name;
            const values = attr.values?.map((v) => (typeof v === 'string' ? v : v.name));
            if (!props.some((p) => p.name === propName)) {
              props.push({
                name: propName,
                description: attr.description,
                values,
              });
            }
          }
        }
      }
    }
  }

  // Extract props from <script>
  const scriptMatch = content.match(/<script\b[^>]*>([\s\S]*?)<\/script>/i);
  if (scriptMatch) {
    const scriptText = scriptMatch[1];

    // Props array: props: ['a', 'b']
    const arrayPropsMatch = scriptText.match(/props\s*:\s*\[([\s\S]*?)\]/);
    if (arrayPropsMatch) {
      const items = arrayPropsMatch[1].match(/['"]([^'"]+)['"]/g);
      if (items) {
        for (const item of items) {
          const propName = item.replace(/['"]/g, '').trim();
          if (propName && !props.some((p) => p.name === propName)) {
            props.push({ name: propName });
          }
        }
      }
    }

    // Props object: props: { label: { type: String, default: '' }, ... }
    const objectPropsMatch = scriptText.match(/props\s*:\s*\{([\s\S]*?)\n\s*\},?\s*(?:setup|data|computed|methods|emits|mounted)/);
    if (objectPropsMatch) {
      const body = objectPropsMatch[1];
      const propBlockRe = /([a-zA-Z_]\w*)\s*:\s*\{([\s\S]*?)\}/g;
      let pbm: RegExpExecArray | null;
      while ((pbm = propBlockRe.exec(body)) !== null) {
        const propName = pbm[1];
        const propDetails = pbm[2];
        const typeMatch = propDetails.match(/type\s*:\s*([A-Za-z0-9_\[\],\s]+)/);
        const defaultMatch = propDetails.match(/default\s*:\s*([^,\n}]+)/);
        if (!props.some((p) => p.name === propName)) {
          props.push({
            name: propName,
            type: typeMatch?.[1].trim(),
            default: defaultMatch?.[1].trim(),
          });
        }
      }
    }

    // Emits: emits: ['click', 'change']
    const emitsMatch = scriptText.match(/emits\s*:\s*\[([\s\S]*?)\]/);
    if (emitsMatch) {
      const eItems = emitsMatch[1].match(/['"]([^'"]+)['"]/g);
      if (eItems) {
        for (const ei of eItems) {
          const emitName = ei.replace(/['"]/g, '').trim();
          if (emitName && !emits.some((e) => e.name === emitName)) {
            emits.push({ name: emitName });
          }
        }
      }
    }
  }

  // Extract slots from <template>
  const templateMatch = content.match(/<template\b[^>]*>([\s\S]*?)<\/template>/i);
  if (templateMatch) {
    const templateText = templateMatch[1];
    const slotRe = /<slot\b([^>]*)>/gi;
    let sm: RegExpExecArray | null;
    let hasDefaultSlot = false;

    while ((sm = slotRe.exec(templateText)) !== null) {
      const attrs = sm[1];
      const nameMatch = attrs.match(/name\s*=\s*['"]([^'"]+)['"]/);
      if (nameMatch) {
        const slotName = nameMatch[1];
        if (!slots.some((s) => s.name === slotName)) {
          slots.push({ name: slotName });
        }
      } else {
        hasDefaultSlot = true;
      }
    }

    if (hasDefaultSlot && !slots.some((s) => s.name === 'default')) {
      slots.unshift({ name: 'default', description: 'Default slot content' });
    }
  }

  const isBuiltin = filePath.includes(path.join('Core', 'UI', 'Phui'));

  return {
    pascalName,
    kebabName,
    filePath,
    description: description || `Phue component <${kebabName}>`,
    props,
    slots,
    emits,
    isBuiltin,
  };
}

export class ComponentRegistry {
  private components = new Map<string, DiscoveredComponent>();
  private customData?: { tags?: Array<any> };

  constructor(customData?: { tags?: Array<any> }) {
    this.customData = customData;
  }

  public register(comp: DiscoveredComponent): void {
    this.components.set(comp.pascalName, comp);
    this.components.set(comp.kebabName, comp);
  }

  public unregister(filePath: string): void {
    for (const [key, comp] of this.components.entries()) {
      if (comp.filePath === filePath) {
        this.components.delete(key);
      }
    }
  }

  public find(name: string): DiscoveredComponent | undefined {
    return this.components.get(name) || this.components.get(toPascalCase(name)) || this.components.get(toKebabCase(name));
  }

  public getAll(): DiscoveredComponent[] {
    const unique = new Map<string, DiscoveredComponent>();
    for (const comp of this.components.values()) {
      unique.set(comp.pascalName, comp);
    }
    return Array.from(unique.values()).sort((a, b) => a.kebabName.localeCompare(b.kebabName));
  }

  public scanFile(filePath: string): DiscoveredComponent | undefined {
    try {
      if (!fs.existsSync(filePath) || !filePath.endsWith('.phue')) return undefined;
      const content = fs.readFileSync(filePath, 'utf8');
      const comp = extractComponentMetadata(content, filePath, this.customData);
      this.register(comp);
      return comp;
    } catch {
      return undefined;
    }
  }

  public scanDirectory(dirPath: string): DiscoveredComponent[] {
    const results: DiscoveredComponent[] = [];
    if (!fs.existsSync(dirPath)) return results;

    const entries = fs.readdirSync(dirPath, { withFileTypes: true });
    for (const entry of entries) {
      const fullPath = path.join(dirPath, entry.name);
      if (entry.isDirectory()) {
        if (entry.name !== 'node_modules' && entry.name !== 'vendor' && entry.name !== '.git') {
          results.push(...this.scanDirectory(fullPath));
        }
      } else if (entry.isFile() && entry.name.endsWith('.phue')) {
        const comp = this.scanFile(fullPath);
        if (comp) results.push(comp);
      }
    }
    return results;
  }
}

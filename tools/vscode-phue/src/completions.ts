import * as vscode from 'vscode';
import { detectBlock, getAttributePrefix, getOpenTagName, getTagNamePrefix, inNamePosition, inSlotPosition, insideQuotedValue } from './context';
import { ComponentRegistry, DiscoveredComponent } from './discovery';
import { hasPhpBlock } from './parser';

const VUE_DIRECTIVES = [
  { name: 'v-if', desc: 'Conditionally render an element or a template fragment.' },
  { name: 'v-else-if', desc: 'Conditional "else-if" chain for v-if.' },
  { name: 'v-else', desc: 'Conditional "else" block for v-if or v-else-if.' },
  { name: 'v-for', desc: 'Render the element or template block multiple times based on source data.' },
  { name: 'v-model', desc: 'Create two-way data binding on form inputs or components.' },
  { name: 'v-show', desc: 'Toggle the element visibility by updating CSS display property.' },
  { name: 'v-bind', desc: 'Dynamically bind one or more attributes or component props.' },
  { name: 'v-on', desc: 'Attach event listeners to the element.' },
  { name: 'v-slot', desc: 'Denote named slots or scoped slots that expect props.' },
  { name: 'v-html', desc: 'Update the element innerHTML with raw HTML string.' },
  { name: 'v-text', desc: 'Update the element text content.' },
  { name: 'v-once', desc: 'Render the element and component once only, skipping future updates.' },
  { name: 'v-pre', desc: 'Skip compilation for this element and all its children.' },
  { name: 'v-cloak', desc: 'Keeps hidden until compilation is finished.' },
];

const COMMON_HTML_TAGS = [
  'div', 'span', 'p', 'a', 'button', 'input', 'form', 'label', 'select', 'option',
  'textarea', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'thead', 'tbody',
  'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'nav', 'main', 'footer', 'section', 'article',
];

const VUE_BUILTIN_TAGS = ['component', 'transition', 'transition-group', 'keep-alive', 'teleport', 'slot', 'template'];

export class PhueCompletionProvider implements vscode.CompletionItemProvider {
  constructor(private registry: ComponentRegistry) {}

  public provideCompletionItems(
    document: vscode.TextDocument,
    position: vscode.Position,
  ): vscode.CompletionItem[] | undefined {
    const lineBefore = document.lineAt(position).text.slice(0, position.character);
    const wholeBefore = document.getText(new vscode.Range(new vscode.Position(0, 0), position));
    const fullText = document.getText();
    const block = detectBlock(wholeBefore);

    // 1. OUTSIDE BLOCKS (block === 'none')
    if (block === 'none') {
      if (inNamePosition(lineBefore)) {
        const prefix = getTagNamePrefix(lineBefore);
        const items: vscode.CompletionItem[] = [];

        // <template>
        if ('template'.startsWith(prefix)) {
          const item = new vscode.CompletionItem('template', vscode.CompletionItemKind.Struct);
          item.insertText = new vscode.SnippetString('template>\n  ${0}\n</template>');
          item.detail = 'Vue 3 Template block';
          items.push(item);
        }

        // <script>
        if ('script'.startsWith(prefix)) {
          const item = new vscode.CompletionItem('script', vscode.CompletionItemKind.Struct);
          item.insertText = new vscode.SnippetString(
            "script>\nimport { ref, computed } from 'vue';\n\nexport default {\n  setup() {\n    ${0}\n    return {};\n  }\n};\n</script>",
          );
          item.detail = 'Client JavaScript block';
          items.push(item);
        }

        // <style>
        if ('style'.startsWith(prefix)) {
          const item = new vscode.CompletionItem('style', vscode.CompletionItemKind.Struct);
          item.insertText = new vscode.SnippetString('style scoped>\n${0}\n</style>');
          item.detail = 'Component CSS Style block';
          items.push(item);
        }

        // <php> — ONLY OFFER IF NOT ALREADY PRESENT IN THE DOCUMENT!
        const documentHasPhp = hasPhpBlock(fullText);
        if (!documentHasPhp && 'php'.startsWith(prefix)) {
          const item = new vscode.CompletionItem('php', vscode.CompletionItemKind.Struct);
          item.insertText = new vscode.SnippetString(
            "php>\n\\$title = '${1:Page Title}';\n\nreturn [\n    'title' => \\$title,\n    'state' => [\n        ${0}\n    ],\n];\n</php>",
          );
          item.detail = 'Server-side PHP state provider block (max 1 allowed)';
          items.push(item);
        }

        return items;
      }
      return undefined;
    }

    // 2. INSIDE <template>
    if (block === 'template') {
      // In tag-name position: `<ph|`
      if (inNamePosition(lineBefore)) {
        const prefix = getTagNamePrefix(lineBefore);
        return this.getTagCompletions(prefix);
      }

      // In slot attribute position: `<template #|`
      if (inSlotPosition(lineBefore)) {
        return this.getSlotCompletions(wholeBefore);
      }

      // Inside tag attributes: `<ph-button |`
      if (!insideQuotedValue(lineBefore)) {
        const tagName = getOpenTagName(lineBefore);
        if (tagName) {
          const prefix = getAttributePrefix(lineBefore);
          return this.getAttributeCompletions(tagName, prefix);
        }
      }
    }

    // 3. INSIDE <php>
    if (block === 'php') {
      return this.getPhpCompletions(lineBefore);
    }

    return undefined;
  }

  private getTagCompletions(prefix: string): vscode.CompletionItem[] {
    const items: vscode.CompletionItem[] = [];
    const lowerPrefix = prefix.toLowerCase();

    // 1. Phue & Workspace Components
    for (const comp of this.registry.getAll()) {
      if (comp.kebabName.toLowerCase().startsWith(lowerPrefix) || comp.pascalName.toLowerCase().startsWith(lowerPrefix)) {
        const item = new vscode.CompletionItem(comp.kebabName, vscode.CompletionItemKind.Class);
        item.insertText = new vscode.SnippetString(`${comp.kebabName}>\${0}</${comp.kebabName}>`);
        item.detail = `Phue Component <${comp.pascalName}>`;

        const md = new vscode.MarkdownString();
        if (comp.description) md.appendMarkdown(`${comp.description}\n\n`);
        if (comp.props.length > 0) {
          md.appendMarkdown(`**Props:** \`${comp.props.map((p) => p.name).join('`, `')}\`\n\n`);
        }
        if (comp.slots.length > 0) {
          md.appendMarkdown(`**Slots:** \`${comp.slots.map((s) => s.name).join('`, `')}\`\n\n`);
        }
        item.documentation = md;
        items.push(item);
      }
    }

    // 2. Standard HTML Tags
    for (const tag of COMMON_HTML_TAGS) {
      if (tag.startsWith(lowerPrefix)) {
        const item = new vscode.CompletionItem(tag, vscode.CompletionItemKind.Property);
        item.insertText = new vscode.SnippetString(`${tag}>\${0}</${tag}>`);
        item.detail = `HTML Element <${tag}>`;
        items.push(item);
      }
    }

    // 3. Vue Built-in Tags
    for (const tag of VUE_BUILTIN_TAGS) {
      if (tag.startsWith(lowerPrefix)) {
        const item = new vscode.CompletionItem(tag, vscode.CompletionItemKind.Keyword);
        item.insertText = new vscode.SnippetString(`${tag}>\${0}</${tag}>`);
        item.detail = `Vue Built-in Element <${tag}>`;
        items.push(item);
      }
    }

    return items;
  }

  private getAttributeCompletions(tagName: string, prefix: string): vscode.CompletionItem[] {
    const items: vscode.CompletionItem[] = [];
    const comp = this.registry.find(tagName);

    if (comp) {
      // Component Props
      for (const prop of comp.props) {
        if (prop.name.startsWith(prefix) || `:${prop.name}`.startsWith(prefix)) {
          const item = new vscode.CompletionItem(prop.name, vscode.CompletionItemKind.Field);
          if (prop.values && prop.values.length > 0) {
            item.insertText = new vscode.SnippetString(`${prop.name}="\${1|${prop.values.join(',')}|}"`);
          } else {
            item.insertText = new vscode.SnippetString(`${prop.name}="\${0}"`);
          }
          item.detail = `prop: ${prop.name}${prop.type ? ` (${prop.type})` : ''}`;
          if (prop.description) item.documentation = new vscode.MarkdownString(prop.description);
          items.push(item);

          // Dynamic bound prop (:prop)
          const boundItem = new vscode.CompletionItem(`:${prop.name}`, vscode.CompletionItemKind.Field);
          boundItem.insertText = new vscode.SnippetString(`:${prop.name}="\${0}"`);
          boundItem.detail = `bound prop: :${prop.name}`;
          items.push(boundItem);
        }
      }

      // Component Emits (@event)
      for (const emit of comp.emits) {
        const emitName = `@${emit.name}`;
        if (emitName.startsWith(prefix) || emit.name.startsWith(prefix)) {
          const item = new vscode.CompletionItem(emitName, vscode.CompletionItemKind.Event);
          item.insertText = new vscode.SnippetString(`${emitName}="\${0}"`);
          item.detail = `event: @${emit.name}`;
          if (emit.description) item.documentation = new vscode.MarkdownString(emit.description);
          items.push(item);
        }
      }
    }

    // Vue Directives
    for (const dir of VUE_DIRECTIVES) {
      if (dir.name.startsWith(prefix)) {
        const item = new vscode.CompletionItem(dir.name, vscode.CompletionItemKind.Keyword);
        item.insertText = new vscode.SnippetString(`${dir.name}="\${0}"`);
        item.detail = `Vue Directive ${dir.name}`;
        item.documentation = new vscode.MarkdownString(dir.desc);
        items.push(item);
      }
    }

    return items;
  }

  private getSlotCompletions(wholeBefore: string): vscode.CompletionItem[] {
    const items: vscode.CompletionItem[] = [];

    // Search backwards for the containing component tag
    const componentRe = /<([a-zA-Z][\w.-]*)\b[^>]*>/g;
    let match: RegExpExecArray | null;
    let parentTag: string | undefined;

    while ((match = componentRe.exec(wholeBefore)) !== null) {
      const tag = match[1];
      if (tag !== 'template' && !COMMON_HTML_TAGS.includes(tag)) {
        parentTag = tag;
      }
    }

    if (parentTag) {
      const comp = this.registry.find(parentTag);
      if (comp && comp.slots.length > 0) {
        for (const slot of comp.slots) {
          const item = new vscode.CompletionItem(`#${slot.name}`, vscode.CompletionItemKind.Value);
          item.insertText = new vscode.SnippetString(`#${slot.name}>$0</template>`);
          item.detail = `Slot #${slot.name} of <${comp.pascalName}>`;
          items.push(item);
        }
      }
    }

    // Always include #default
    if (!items.some((i) => i.label === '#default')) {
      const item = new vscode.CompletionItem('#default', vscode.CompletionItemKind.Value);
      item.insertText = new vscode.SnippetString('#default>$0</template>');
      item.detail = 'Default slot';
      items.push(item);
    }

    return items;
  }

  private getPhpCompletions(lineBefore: string): vscode.CompletionItem[] {
    const items: vscode.CompletionItem[] = [];

    const returnItem = new vscode.CompletionItem('return state', vscode.CompletionItemKind.Snippet);
    returnItem.insertText = new vscode.SnippetString(
      "return [\n    'title' => \\$title ?? '${1:Page Title}',\n    'state' => [\n        ${0}\n    ],\n    'scripts' => [],\n];",
    );
    returnItem.detail = 'Phue State Provider return array';
    items.push(returnItem);

    const configItem = new vscode.CompletionItem('$config', vscode.CompletionItemKind.Snippet);
    configItem.insertText = new vscode.SnippetString("\\$config = [\n    ${0}\n];");
    configItem.detail = 'Component config array';
    items.push(configItem);

    return items;
  }
}

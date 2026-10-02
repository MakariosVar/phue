import * as vscode from 'vscode';
import { ComponentRegistry } from './discovery';

export class PhueHoverProvider implements vscode.HoverProvider {
  constructor(private registry: ComponentRegistry) {}

  public provideHover(
    document: vscode.TextDocument,
    position: vscode.Position,
  ): vscode.Hover | undefined {
    const range = document.getWordRangeAtPosition(position, /[:@#]?[\w-]+/);
    if (!range) return undefined;

    const word = document.getText(range);
    const line = document.lineAt(position).text;

    // 1. Hover on Component Tag Name (<ph-button)
    const comp = this.registry.find(word);
    if (comp) {
      const md = new vscode.MarkdownString();
      md.appendMarkdown(`### \`<${comp.pascalName}>\` (\`${comp.kebabName}\`)\n\n`);
      if (comp.description) md.appendMarkdown(`${comp.description}\n\n`);

      md.appendMarkdown(`**Source:** \`${vscode.workspace.asRelativePath(comp.filePath)}\`\n\n`);

      if (comp.props.length > 0) {
        md.appendMarkdown(`#### Props\n\n`);
        md.appendMarkdown(`| Prop | Type | Default | Description |\n| --- | --- | --- | --- |\n`);
        for (const p of comp.props) {
          md.appendMarkdown(`| \`${p.name}\` | \`${p.type || 'any'}\` | \`${p.default || '-'}\` | ${p.description || '-'} |\n`);
        }
        md.appendMarkdown('\n');
      }

      if (comp.slots.length > 0) {
        md.appendMarkdown(`#### Slots\n\n`);
        for (const s of comp.slots) {
          md.appendMarkdown(`- \`#${s.name}\`${s.description ? `: ${s.description}` : ''}\n`);
        }
        md.appendMarkdown('\n');
      }

      if (comp.emits.length > 0) {
        md.appendMarkdown(`#### Emits\n\n`);
        for (const e of comp.emits) {
          md.appendMarkdown(`- \`@${e.name}\`${e.description ? `: ${e.description}` : ''}\n`);
        }
        md.appendMarkdown('\n');
      }

      return new vscode.Hover(md, range);
    }

    // 2. Hover on Prop or Event
    const tagMatch = line.slice(0, position.character).match(/<([A-Za-z][\w.-]*)/);
    if (tagMatch) {
      const parentComp = this.registry.find(tagMatch[1]);
      if (parentComp) {
        const cleanWord = word.replace(/^[:@]/, '');
        const prop = parentComp.props.find((p) => p.name === cleanWord);
        if (prop) {
          const md = new vscode.MarkdownString();
          md.appendMarkdown(`**Prop \`${prop.name}\`** of \`<${parentComp.pascalName}>\`\n\n`);
          if (prop.type) md.appendMarkdown(`- Type: \`${prop.type}\`\n`);
          if (prop.default) md.appendMarkdown(`- Default: \`${prop.default}\`\n`);
          if (prop.values) md.appendMarkdown(`- Values: \`${prop.values.join('`, `')}\`\n`);
          if (prop.description) md.appendMarkdown(`\n${prop.description}\n`);
          return new vscode.Hover(md, range);
        }

        const emit = parentComp.emits.find((e) => e.name === cleanWord);
        if (emit) {
          const md = new vscode.MarkdownString();
          md.appendMarkdown(`**Event \`@${emit.name}\`** of \`<${parentComp.pascalName}>\`\n\n`);
          if (emit.description) md.appendMarkdown(`${emit.description}\n`);
          return new vscode.Hover(md, range);
        }
      }
    }

    return undefined;
  }
}

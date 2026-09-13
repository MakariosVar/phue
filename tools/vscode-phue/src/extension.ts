import * as vscode from 'vscode';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { detectBlock, getAttributePrefix, getOpenTagName, getTagNamePrefix, inNamePosition, insideQuotedValue } from './context';

interface PhuiTag {
  name: string;
  description?: string;
  attributes?: { name: string; description?: string }[];
}

const VUE_DIRECTIVES = ['v-if', 'v-else', 'v-else-if', 'v-show', 'v-for', 'v-model', 'v-on', 'v-bind', 'v-once', 'v-pre', 'v-cloak', 'v-html', 'v-text', 'v-slot'];
const BLOCK_TAGS = ['template', 'script', 'php'];

function makeItems(names: string[], kind: vscode.CompletionItemKind, snippet: (n: string) => string): vscode.CompletionItem[] {
  return [...new Set(names)].map((n) => {
    const item = new vscode.CompletionItem(n, kind);
    item.insertText = new vscode.SnippetString(snippet(n));
    return item;
  });
}

export function activate(context: vscode.ExtensionContext): void {
  const dataPath = path.join(context.extensionPath, 'custom-data', 'phui-custom-data.json');
  const custom = JSON.parse(fs.readFileSync(dataPath, 'utf8')) as { tags?: PhuiTag[] };
  const tags: PhuiTag[] = custom.tags ?? [];

  const provider: vscode.CompletionItemProvider = {
    provideCompletionItems(document, position) {
      const lineBefore = document.lineAt(position).text.slice(0, position.character);
      const wholeBefore = document.getText(new vscode.Range(new vscode.Position(0, 0), position));
      const block = detectBlock(wholeBefore);

      if (inNamePosition(lineBefore)) {
        const prefix = getTagNamePrefix(lineBefore);
        if (block === 'template') {
          return makeItems(
            tags.filter((t) => t.name.startsWith(prefix)).map((t) => t.name),
            vscode.CompletionItemKind.Class,
            (n) => `${n}>\${0}</${n}>`,
          );
        }
        if (block === 'none') {
          return makeItems(
            BLOCK_TAGS.filter((b) => b.startsWith(prefix)),
            vscode.CompletionItemKind.Struct,
            (n) => `${n}>\${0}</${n}>`,
          );
        }
        return undefined;
      }

      if (block === 'template' && !insideQuotedValue(lineBefore)) {
        const tagName = getOpenTagName(lineBefore);
        if (tagName) {
          const tag = tags.find((t) => t.name === tagName);
          const prefix = getAttributePrefix(lineBefore);
          const names = [...(tag?.attributes ?? []).map((a) => a.name), ...VUE_DIRECTIVES];
          return makeItems(
            names.filter((n) => n.startsWith(prefix)),
            vscode.CompletionItemKind.Field,
            (n) => `${n}="\${0}"`,
          );
        }
      }

      return undefined;
    },
  };

  context.subscriptions.push(
    vscode.languages.registerCompletionItemProvider(
      { language: 'phue', scheme: 'file' },
      provider,
      '<',
      ' ',
      ':',
      '@',
    ),
  );
}

export function deactivate(): void {}
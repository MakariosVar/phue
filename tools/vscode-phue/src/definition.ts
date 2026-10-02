import * as vscode from 'vscode';
import { ComponentRegistry } from './discovery';

export class PhueDefinitionProvider implements vscode.DefinitionProvider {
  constructor(private registry: ComponentRegistry) {}

  public provideDefinition(
    document: vscode.TextDocument,
    position: vscode.Position,
  ): vscode.Definition | undefined {
    const range = document.getWordRangeAtPosition(position, /[a-zA-Z][\w.-]*/);
    if (!range) return undefined;

    const word = document.getText(range);
    const comp = this.registry.find(word);

    if (comp && comp.filePath) {
      return new vscode.Location(vscode.Uri.file(comp.filePath), new vscode.Position(0, 0));
    }

    return undefined;
  }
}

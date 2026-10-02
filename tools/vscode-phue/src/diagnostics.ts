import * as vscode from 'vscode';
import { parsePhueSfc } from './parser';
import { lintPhpCode } from './phpLint';

export class PhueDiagnosticsManager {
  private collection: vscode.DiagnosticCollection;
  private debounceTimers = new Map<string, NodeJS.Timeout>();

  constructor(context: vscode.ExtensionContext) {
    this.collection = vscode.languages.createDiagnosticCollection('phue');
    context.subscriptions.push(this.collection);
  }

  public updateDiagnostics(document: vscode.TextDocument): void {
    if (document.languageId !== 'phue') return;

    const uriStr = document.uri.toString();
    const existing = this.debounceTimers.get(uriStr);
    if (existing) clearTimeout(existing);

    const timer = setTimeout(async () => {
      this.debounceTimers.delete(uriStr);
      await this.runDiagnostics(document);
    }, 250);

    this.debounceTimers.set(uriStr, timer);
  }

  public clear(uri: vscode.Uri): void {
    this.collection.delete(uri);
    const existing = this.debounceTimers.get(uri.toString());
    if (existing) {
      clearTimeout(existing);
      this.debounceTimers.delete(uri.toString());
    }
  }

  private async runDiagnostics(document: vscode.TextDocument): Promise<void> {
    const text = document.getText();
    const parsed = parsePhueSfc(text);
    const diagnostics: vscode.Diagnostic[] = [];

    // 1. Enforce SFC Block Structure & Single <php> Block Rule
    for (const err of parsed.validationErrors) {
      const startPos = document.positionAt(err.startOffset);
      const endPos = document.positionAt(err.endOffset);
      const range = new vscode.Range(startPos, endPos);

      const diag = new vscode.Diagnostic(
        range,
        err.message,
        err.severity === 'error' ? vscode.DiagnosticSeverity.Error : vscode.DiagnosticSeverity.Warning,
      );
      diag.source = 'phue';
      diag.code = err.code;
      diagnostics.push(diag);
    }

    // 2. Validate PHP Syntax in <php> block (if present and valid single block)
    const config = vscode.workspace.getConfiguration('phue');
    const validatePhp = config.get<boolean>('php.validate', true);

    if (validatePhp && parsed.phpBlocks.length >= 1) {
      const primaryPhp = parsed.phpBlocks[0];
      const phpErrors = await lintPhpCode(primaryPhp.content);

      for (const pErr of phpErrors) {
        // Line number inside <php> content (0-indexed)
        const targetLine = primaryPhp.startLine + 1 + pErr.line;
        if (targetLine < document.lineCount) {
          const lineObj = document.lineAt(targetLine);
          const range = lineObj.range;

          const diag = new vscode.Diagnostic(
            range,
            pErr.message,
            vscode.DiagnosticSeverity.Error,
          );
          diag.source = 'phue (php)';
          diag.code = 'phue.php-syntax-error';
          diagnostics.push(diag);
        }
      }
    }

    this.collection.set(document.uri, diagnostics);
  }
}

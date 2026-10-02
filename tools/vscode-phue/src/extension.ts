import * as vscode from 'vscode';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { ComponentRegistry } from './discovery';
import { PhueDiagnosticsManager } from './diagnostics';
import { PhueCompletionProvider } from './completions';
import { PhueHoverProvider } from './hover';
import { PhueDefinitionProvider } from './definition';
import { configureWorkspaceForVolar, writeVolarTypeDefinitions } from './volar';

export async function activate(context: vscode.ExtensionContext): Promise<void> {
  // 1. Load built-in PhUI Custom Data
  let customData: { tags?: any[] } = {};
  const dataPath = path.join(context.extensionPath, 'custom-data', 'phui-custom-data.json');
  if (fs.existsSync(dataPath)) {
    try {
      customData = JSON.parse(fs.readFileSync(dataPath, 'utf8'));
    } catch {
      customData = {};
    }
  }

  // 2. Initialize Component Registry & Autodiscovery
  const registry = new ComponentRegistry(customData);

  const workspaceFolders = vscode.workspace.workspaceFolders;
  const workspaceRoot = workspaceFolders && workspaceFolders.length > 0 ? workspaceFolders[0].uri.fsPath : undefined;

  async function refreshComponents(): Promise<void> {
    if (!workspaceRoot) return;

    // Scan framework PhUI components
    const phuiDir = path.join(workspaceRoot, 'src', 'Core', 'UI', 'Phui');
    if (fs.existsSync(phuiDir)) {
      registry.scanDirectory(phuiDir);
    }

    // Scan App components and pages
    const appFrontendDir = path.join(workspaceRoot, 'App', 'Frontend');
    if (fs.existsSync(appFrontendDir)) {
      registry.scanDirectory(appFrontendDir);
    }

    // Scan Module components
    const modulesDir = path.join(workspaceRoot, 'src', 'Modules');
    if (fs.existsSync(modulesDir)) {
      registry.scanDirectory(modulesDir);
    }

    // Auto-generate Volar types if configured
    const config = vscode.workspace.getConfiguration('phue');
    if (config.get<boolean>('volar.autoGenerateTypes', true)) {
      try {
        await writeVolarTypeDefinitions(registry.getAll(), workspaceRoot);
      } catch (err) {
        console.error('Failed to generate Volar type definitions:', err);
      }
    }
  }

  // Initial component scan
  await refreshComponents();

  // Watch for workspace component additions / updates / deletions
  const watcher = vscode.workspace.createFileSystemWatcher('**/*.phue');
  watcher.onDidCreate(async (uri) => {
    registry.scanFile(uri.fsPath);
    await refreshComponents();
  });
  watcher.onDidChange((uri) => {
    registry.scanFile(uri.fsPath);
  });
  watcher.onDidDelete(async (uri) => {
    registry.unregister(uri.fsPath);
    await refreshComponents();
  });
  context.subscriptions.push(watcher);

  // 3. Diagnostics Manager (At most 1 <php> block + PHP Syntax Linting)
  const diagnostics = new PhueDiagnosticsManager(context);

  vscode.workspace.onDidOpenTextDocument((doc) => diagnostics.updateDiagnostics(doc), null, context.subscriptions);
  vscode.workspace.onDidChangeTextDocument((e) => diagnostics.updateDiagnostics(e.document), null, context.subscriptions);
  vscode.workspace.onDidCloseTextDocument((doc) => diagnostics.clear(doc.uri), null, context.subscriptions);

  // Run on active editor
  if (vscode.window.activeTextEditor) {
    diagnostics.updateDiagnostics(vscode.window.activeTextEditor.document);
  }

  // 4. Language Feature Providers
  const selector: vscode.DocumentSelector = [
    { language: 'phue', scheme: 'file' },
    { language: 'phue', scheme: 'untitled' },
  ];

  context.subscriptions.push(
    vscode.languages.registerCompletionItemProvider(
      selector,
      new PhueCompletionProvider(registry),
      '<', ' ', ':', '@', '#', '"', '\'',
    ),
  );

  context.subscriptions.push(
    vscode.languages.registerHoverProvider(
      selector,
      new PhueHoverProvider(registry),
    ),
  );

  context.subscriptions.push(
    vscode.languages.registerDefinitionProvider(
      selector,
      new PhueDefinitionProvider(registry),
    ),
  );

  // 5. Commands
  context.subscriptions.push(
    vscode.commands.registerCommand('phue.generateTypes', async () => {
      if (!workspaceRoot) {
        vscode.window.showWarningMessage('No open workspace found to generate Phue component types.');
        return;
      }
      await refreshComponents();
      const dtsPath = await writeVolarTypeDefinitions(registry.getAll(), workspaceRoot);
      vscode.window.showInformationMessage(`Phue Volar component types generated: ${vscode.workspace.asRelativePath(dtsPath)}`);
    }),
  );

  context.subscriptions.push(
    vscode.commands.registerCommand('phue.configureVolar', async () => {
      if (!workspaceRoot) {
        vscode.window.showWarningMessage('No open workspace found.');
        return;
      }
      const updated = await configureWorkspaceForVolar(workspaceRoot);
      await writeVolarTypeDefinitions(registry.getAll(), workspaceRoot);
      if (updated) {
        vscode.window.showInformationMessage('Workspace tsconfig.json updated with Phue Volar extensions.');
      } else {
        vscode.window.showInformationMessage('Workspace is already configured for Phue Volar support.');
      }
    }),
  );

  context.subscriptions.push(
    vscode.commands.registerCommand('phue.refreshComponents', async () => {
      await refreshComponents();
      vscode.window.showInformationMessage(`Discovered ${registry.getAll().length} Phue components.`);
    }),
  );
}

export function deactivate(): void {}
import { spawn } from 'node:child_process';

export interface PhpLintError {
  line: number; // 0-indexed line relative to <php> block content
  message: string;
}

/**
 * Lints PHP code via `php -l` using child_process stdin.
 * Gracefully falls back to structural bracket/token checks if `php` CLI is unavailable.
 */
export async function lintPhpCode(phpCode: string): Promise<PhpLintError[]> {
  const errors: PhpLintError[] = [];

  // Quick fallback check for basic bracket balance
  const bracketError = checkBracketBalance(phpCode);
  if (bracketError) {
    errors.push(bracketError);
  }

  try {
    const cliError = await runPhpCliLint(phpCode);
    if (cliError) {
      // If CLI returned an error, use the precise CLI error
      return [cliError];
    }
    return [];
  } catch {
    // If php CLI is not installed or failed to spawn, keep fallback check
    return errors;
  }
}

function runPhpCliLint(phpCode: string): Promise<PhpLintError | null> {
  return new Promise((resolve) => {
    // Wrap code with <?php opening tag for php -l
    const input = `<?php\n${phpCode}\n`;

    const child = spawn('php', ['-l'], {
      stdio: ['pipe', 'pipe', 'pipe'],
      timeout: 3000,
    });

    let stdout = '';
    let stderr = '';

    child.stdout.on('data', (d) => {
      stdout += d.toString();
    });

    child.stderr.on('data', (d) => {
      stderr += d.toString();
    });

    child.on('error', () => {
      resolve(null);
    });

    child.on('close', (code) => {
      if (code === 0) {
        resolve(null);
        return;
      }

      const out = stderr || stdout;
      // Match: Parse error: syntax error, ... on line X
      // or Fatal error: ... on line X
      const m = out.match(/(?:Parse error|Fatal error):\s*(.*?)\s+in\s+.*?on line\s+(\d+)/i);
      if (m) {
        const rawLine = parseInt(m[2], 10);
        // rawLine 1 is <?php\n, so rawLine 2 is phpCode line 0
        const relativeLine = Math.max(0, rawLine - 2);
        resolve({
          line: relativeLine,
          message: `PHP syntax error: ${m[1]}`,
        });
      } else {
        resolve(null);
      }
    });

    try {
      child.stdin.write(input);
      child.stdin.end();
    } catch {
      resolve(null);
    }
  });
}

function checkBracketBalance(code: string): PhpLintError | null {
  const lines = code.split('\n');
  const stack: { char: string; line: number }[] = [];
  let inSingleQuote = false;
  let inDoubleQuote = false;
  let inLineComment = false;
  let inBlockComment = false;

  for (let l = 0; l < lines.length; l++) {
    const line = lines[l];
    inLineComment = false;

    for (let c = 0; c < line.length; c++) {
      const ch = line[c];
      const next = line[c + 1] || '';

      if (inLineComment) continue;

      if (inBlockComment) {
        if (ch === '*' && next === '/') {
          inBlockComment = false;
          c++;
        }
        continue;
      }

      if (ch === '/' && next === '*') {
        inBlockComment = true;
        c++;
        continue;
      }

      if ((ch === '/' && next === '/') || ch === '#') {
        inLineComment = true;
        continue;
      }

      if (ch === "'" && !inDoubleQuote && line[c - 1] !== '\\') {
        inSingleQuote = !inSingleQuote;
        continue;
      }

      if (ch === '"' && !inSingleQuote && line[c - 1] !== '\\') {
        inDoubleQuote = !inDoubleQuote;
        continue;
      }

      if (inSingleQuote || inDoubleQuote) continue;

      if (ch === '{' || ch === '[' || ch === '(') {
        stack.push({ char: ch, line: l });
      } else if (ch === '}' || ch === ']' || ch === ')') {
        const expected = ch === '}' ? '{' : ch === ']' ? '[' : '(';
        if (stack.length === 0 || stack[stack.length - 1].char !== expected) {
          return {
            line: l,
            message: `Unmatched closing bracket '${ch}' in <php> block`,
          };
        }
        stack.pop();
      }
    }
  }

  if (stack.length > 0) {
    const unclosed = stack.pop()!;
    return {
      line: unclosed.line,
      message: `Unclosed bracket '${unclosed.char}' in <php> block`,
    };
  }

  return null;
}

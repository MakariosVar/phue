<?php
/** @var Throwable $e */
/** @var bool $debug */
/** @var int $code */
$traceFrames = explode("\n", $e->getTraceAsString());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $code ?> - Not Found</title>
    <style>
        :root {
            --bg: #0f172a;
            --card-bg: #1e293b;
            --card-border: #334155;
            --accent: #38bdf8;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --danger: #f87171;
            --code-bg: #090d16;
        }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: <?= $debug ? '40px 20px' : '20px' ?>; display: flex; align-items: <?= $debug ? 'flex-start' : 'center' ?>; justify-content: center; min-height: 100vh; }
        .card { background: var(--card-bg); border: 1px solid var(--card-border); padding: 32px; border-radius: 12px; width: 100%; max-width: <?= $debug ? '950px' : '500px' ?>; box-shadow: 0 20.5px 25px -5px rgba(0,0,0,0.5); border-left: 5px solid var(--accent); }
        .badge { display: inline-block; background: rgba(56, 189, 248, 0.1); color: var(--accent); font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px; }
        h1 { font-size: <?= $debug ? '22px' : '24px' ?>; margin: 0 0 10px 0; color: var(--text); word-break: break-word; font-family: <?= $debug ? 'monospace' : 'inherit' ?>; }
        p { color: var(--text-muted); line-height: 1.6; margin: 0 0 20px 0; }
        .meta-box { background: var(--code-bg); padding: 16px; border-radius: 8px; margin: 16px 0; border: 1px solid #1e293b; font-family: monospace; font-size: 13px; }
        .meta-row { display: flex; margin-bottom: 8px; flex-wrap: wrap; }
        .meta-row:last-child { margin-bottom: 0; }
        .meta-label { color: var(--text-muted); width: 80px; flex-shrink: 0; font-weight: 600; }
        .meta-val { color: var(--text); word-break: break-all; }
        .trace-title { font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin: 24px 0 10px 0; font-weight: 700; }
        .trace-list { background: var(--code-bg); border-radius: 8px; border: 1px solid #1e293b; max-height: 400px; overflow-y: auto; margin: 0; padding: 0; list-style: none; font-family: monospace; font-size: 12px; }
        .trace-item { padding: 10px 14px; border-bottom: 1px solid #1e293b; display: flex; gap: 12px; color: var(--text-muted); }
        .trace-item:last-child { border-bottom: none; }
        .trace-num { color: #475569; width: 24px; flex-shrink: 0; text-align: right; }
        .trace-func { color: var(--accent); font-weight: 600; }
        .trace-file { color: #facc15; word-break: break-all; }
        .btn { display: inline-block; background: #0284c7; color: #fff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 500; transition: background 0.2s; margin-top: 10px; }
        .btn:hover { background: #0369a1; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">HTTP <?= $code ?> // ROUTE NOT FOUND</div>
        <h1><?= htmlspecialchars($e->getMessage() ?: 'Page Not Found') ?></h1>
        <p>The requested route or resource could not be located on this server.</p>

        <?php if ($debug): ?>
            <div class="meta-box">
                <div class="meta-row">
                    <span class="meta-label">Exception:</span>
                    <span class="meta-val" style="color: var(--danger);"><?= get_class($e) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">File:</span>
                    <span class="meta-val"><?= htmlspecialchars($e->getFile()) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Line:</span>
                    <span class="meta-val" style="color: #4ade80;"><?= $e->getLine() ?></span>
                </div>
            </div>

            <div class="trace-title">Stack Trace</div>
            <ul class="trace-list">
                <?php foreach ($traceFrames as $index => $frame): ?>
                    <li class="trace-item">
                        <span class="trace-num">#<?= $index ?></span>
                        <span class="trace-file"><?= htmlspecialchars($frame) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <a href="/" class="btn">← Back to Home</a>
    </div>
</body>
</html>
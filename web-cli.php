<?php
// vortextz-terminal.php — TAB‑indented CLI page.
// Fixes: PHP syntax error, ensures HTML/JS preview executes.
	error_reporting(E_ALL);
	ini_set('display_errors', 1);

/* ───────────────────────────────────────────────────────────
	1.	Shared settings
   ──────────────────────────────────────────────────────────*/
	$CONFIG_FILE = __DIR__ . '/vortextz-terminal-config.txt';

/* ───────────────────────────────────────────────────────────
	2.	CLI mode
   ──────────────────────────────────────────────────────────*/
	if (php_sapi_name() === 'cli') {
		$argv = $_SERVER['argv'];
		array_shift($argv);                      // remove script name
		$cmd = array_shift($argv) ?: 'help';

		$response = handle_cli_command($cmd . ' ' . implode(' ', $argv), $CONFIG_FILE, true);
		if (isset($response['error'])) {
			fwrite(STDERR, "Error: {$response['error']}\n");
			exit(1);
		}

		if (isset($response['list'])) echo $response['list'] . "\n";
		if (isset($response['code'])) echo $response['code'];
		if (isset($response['msg']))  echo $response['msg'] . "\n";
		exit(0);
	}

/* ───────────────────────────────────────────────────────────
	3.	HTTP JSON endpoint (?cli=...)
   ──────────────────────────────────────────────────────────*/
	if (isset($_GET['cli'])) {
		$out = handle_cli_command(trim($_GET['cli']), $CONFIG_FILE, true, true);
		header('Content-Type: application/json');
		echo json_encode($out);
		exit;
	}

/* ───────────────────────────────────────────────────────────
	4.	Core helper — dispatch
   ──────────────────────────────────────────────────────────*/
	function handle_cli_command(string $cmdLine, string $configFile, bool $createBlob = true, bool $httpMode = false): array {
		$argv = preg_split('/\s+/', trim($cmdLine));
		$cmd  = array_shift($argv) ?: '';

		$rawCmds = readCommandSequence($configFile);
		if (isset($rawCmds['error'])) return ['error' => $rawCmds['error']];

		$commands = [];
		foreach ($rawCmds as $raw) {
			$p = parseCommand($raw);
			if (isset($p['error'])) continue;
			$res = processCode($p);
			$commands[] = ['parsed' => $p, 'result' => $res];
		}

		switch ($cmd) {
			case 'list':
				$rows = [];
				foreach ($commands as $i => $item) {
					$p   = $item['parsed'];
					$idx = $i + 1;
					$in  = "{$p['inputDirectory']}/{$p['inputFileBaseName']}.txt";
					$out = "{$p['outputDirectory']}/{$p['outputFileBaseName']}.txt";
					$rows[] = sprintf("%2d. %s -> %s (rows %d-%d, cols %d-%d)",
						$idx, $in, $out,
						$p['initialRow'], $p['finalRow'],
						$p['initialColumn'], $p['finalColumn']
					);
				}
				return ['list' => implode("\n", $rows)];

			case 'show':
				if (count($argv) < 1 || !ctype_digit($argv[0])) return ['error' => 'Usage: show <index>'];
				$num = (int)$argv[0];
				if ($num < 1 || $num > count($commands)) return ['error' => "Index out of range: $num"];
				$entry = $commands[$num - 1];
				if (isset($entry['result']['error'])) return ['error' => $entry['result']['error']];
				return [
					'code' => $entry['result']['code'],
					'lang' => detectLanguage($entry['result']['code'])
				];

			case 'open':
				if (count($argv) < 1 || !ctype_digit($argv[0])) return ['error' => 'Usage: open <index>'];
				$num = (int)$argv[0];
				if ($num < 1 || $num > count($commands)) return ['error' => "Index out of range: $num"];
				$entry = $commands[$num - 1];
				if (isset($entry['result']['error'])) return ['error' => $entry['result']['error']];
				$blobUrl = create_blob_from_entry($entry, $num, $createBlob, $httpMode);
				return isset($blobUrl['error']) ? $blobUrl : ['url' => $blobUrl];

			default:
				return ['error' => 'Unknown command'];
		}
	}

/* ───────────────────────────────────────────────────────────
	5.	Utility helpers (read, parse, slice, lang)
   ──────────────────────────────────────────────────────────*/
	function readCommandSequence(string $filePath): array {
		if (!file_exists($filePath)) return ['error' => "Command file not found: $filePath"];
		$lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$out = [];
		foreach ($lines as $ln) if ($ln !== '' && $ln[0] === '<') $out[] = substr($ln, 1);
		return $out;
	}

	function parseCommand(string $cmd): array {
		$parts = explode('.', $cmd, 7);
		if (count($parts) < 7) return ['error' => "Invalid command: $cmd"];
		[$id,$colR,$rowR,$inBase,$inDir,$outDir,$last] = $parts;
		[$c1,$c2] = array_pad(explode(',', $colR), 2, PHP_INT_MAX);
		[$r1,$r2] = array_pad(explode(',', $rowR), 2, PHP_INT_MAX);
		$outBase  = preg_replace('/\(<\>\)$/', '', $last);
		return [
			'commandId' => $id,
			'initialColumn' => (int)$c1,
			'finalColumn'   => (int)$c2,
			'initialRow'    => (int)$r1,
			'finalRow'      => (int)$r2,
			'inputFileBaseName' => $inBase,
			'inputDirectory'    => $inDir,
			'outputDirectory'   => $outDir,
			'outputFileBaseName'=> $outBase
		];
	}

	function processCode(array $p): array {
		$src = "{$p['inputDirectory']}/{$p['inputFileBaseName']}.txt";
		if (!file_exists($src)) return ['error' => "File not found: $src"];
		$raw = file($src);
		$code = [];
		if (isset($raw[0]) && ctype_digit(trim($raw[0]))) {
			for ($i = 1; $i < count($raw); $i += 2) $code[] = $raw[$i];
		} else {
			$code = $raw;
		}
		$s = max(0, $p['initialRow'] - 1);
		$e = min(count($code) - 1, $p['finalRow'] - 1);
		$out = [];
		for ($i = $s; $i <= $e; $i++) {
			$ln = $code[$i];
			if ($p['initialColumn'] > 0 || $p['finalColumn'] < PHP_INT_MAX) {
				$st  = max(0, $p['initialColumn'] - 1);
				$len = $p['finalColumn'] - $st;
				$ln  = substr($ln, $st, $len);
			}
			$out[] = $ln;
		}
		$txt = implode('', $out);
		if ($p['outputDirectory'] && $p['outputFileBaseName']) {
			$dst = "{$p['outputDirectory']}/{$p['outputFileBaseName']}.txt";
			if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0777, true);
			file_put_contents($dst, $txt);
		}
		return ['success' => true, 'code' => $txt, 'command' => $p];
	}

	function detectLanguage(string $code): string {
		$h = ltrim($code);
		if (preg_match('/^</', $h)) return 'html';
		if (preg_match('/^(?:function|var|let|const|import|export|class|document\.|console\.|\(function)/i', $h)) return 'javascript';
		return 'plaintext';
	}

	function uid(): string { return 'c_' . uniqid(); }

/* ───────────────────────────────────────────────────────────
	6.	blob builder
   ──────────────────────────────────────────────────────────*/
	function create_blob_from_entry(array $entry, int $num, bool $createBlob, bool $httpMode) {
		$code = $entry['result']['code'];
		$lang = detectLanguage($code);

		if ($lang === 'html') {
			$html = preg_match('/<html/i', $code) ? $code : "<!DOCTYPE html><html><head><title>HTML Preview $num</title></head><body>$code</body></html>";
		} elseif ($lang === 'javascript') {
			$title = "JS Execution $num";
			$html  = "<!DOCTYPE html><html><head><title>$title</title></head><body><script>\n$code\n</script></body></html>";
		} else {
			$escaped = htmlspecialchars($code, ENT_QUOTES);
			$title   = "Code Preview $num";
			$html    = "<!DOCTYPE html><html><head><title>$title</title></head><body><pre>$escaped</pre></body></html>";
		}

		if (!$createBlob) return $html;

		$tmpDir  = __DIR__ . '/tmp';
		if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
		$tmpFile = $tmpDir . '/' . uniqid('blob_', true) . '.html';
		if (!file_put_contents($tmpFile, $html)) return ['error' => "Unable to write $tmpFile"];
		return $httpMode ? 'tmp/' . basename($tmpFile) : "Opened $tmpFile";
	}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Terminal — CLI‑only</title>
	<!--<style>
		body{font-family:monospace;margin:1rem}
		#cliOut{white-space:pre;border:1px solid #888;padding:6px;max-height:260px;overflow:auto}
		#preview{width:100%;height:60vh;border:1px solid #888;margin-top:1rem;display:none}
	</style>-->
	<style>
		/* Vortextz‑Terminal — NASA Engineer UI Blend (2026) */

		/* Import NASA‑style display font */
		@import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap');

		@layer base {
		  :root {
			/* Colors: deep-space & neon accents */
			--color-bg:            #0b0c10;
			--color-text:          #33ff66;
			--color-primary:       #ff6f00;
			--color-primary-hover: #ff8f1c;
			--color-border:        #1a1d22;
			--color-section-bg:    rgba(10,12,16,0.85);

			/* Typography */
			--font-mono:           'Space Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
			--fs-base:             clamp(0.875rem, 1.2vw, 1rem);
			--lh-base:             1.5;

			/* Spacing */
			--space-xs:            0.25rem;
			--space-sm:            0.5rem;
			--space-md:            1rem;
			--space-lg:            1.5rem;
			--space-xl:            2rem;

			/* Radius & shadows */
			--radius-sm:           0.25rem;
			--radius-md:           0.5rem;
			--radius-lg:           0.75rem;
			--shadow-sm:           0 1px 2px rgba(0,0,0,0.3);
			--shadow-md:           0 4px 6px rgba(0,0,0,0.5);

			/* Transitions */
			--ease-fast:           0.2s ease;
		  }

		  /* Global reset & base */
		  *, *::before, *::after { box-sizing: border-box; }
		  html {
			font-size: var(--fs-base);
			-webkit-font-smoothing: antialiased;
			-moz-osx-font-smoothing: grayscale;
			text-shadow: 0 0 2px var(--color-text);
			animation: flicker 3s linear infinite;
		  }
		  body {
			margin: 0;
			min-block-size: 100vh;
			background-color: var(--color-bg);
			color: var(--color-text);
			font-family: var(--font-mono);
			line-height: var(--lh-base);
			display: flex;
			flex-direction: column;
			padding-left: max(var(--space-lg), env(safe-area-inset-left));
			padding-right: max(var(--space-lg), env(safe-area-inset-right));
			padding-bottom: max(var(--space-lg), env(safe-area-inset-bottom));
		  }

		  /* Scanline overlay */
		  body::before {
			content: '';
			position: fixed;
			inset: 0;
			pointer-events: none;
			background: repeating-linear-gradient(
			  transparent 0 1px,
			  rgba(255,255,255,0.03) 1px 2px
			);
			mix-blend-mode: overlay;
			z-index: 9999;
		  }

		  @keyframes flicker {
			0%,100% { opacity:1; }
			5%      { opacity:0.96; }
			10%     { opacity:0.93; }
			15%     { opacity:0.97; }
			20%     { opacity:0.94; }
			25%     { opacity:0.98; }
		  }

		  main {
			container-type: inline-size;
			container-name: terminal-main;
			inline-size: 100%;
			max-inline-size: 1100px;
			margin-inline: auto;
			padding-block: var(--space-lg);
			display: flex;
			flex-direction: column;
			gap: var(--space-xl);
			flex: 1;
		  }

		  /* Accessibility: focus-visible */
		  :focus { outline: none; }
		  :focus-visible {
			outline: 2px solid var(--color-primary);
			outline-offset: 2px;
		  }
		}

		@layer components {
		  .card {
			border: 1px solid var(--color-border);
			border-radius: var(--radius-lg);
			background-color: var(--color-section-bg);
			box-shadow: var(--shadow-sm);
			transition: box-shadow var(--ease-fast);
			padding: var(--space-lg);
			position: relative;
		  }
		  .card:hover { box-shadow: var(--shadow-md); }

		  .terminal-section-title {
			color: var(--color-primary);
			text-shadow: 0 0 4px var(--color-primary);
		  }

		  .control {
			border: 1px solid var(--color-border);
			border-radius: var(--radius-md);
			background: transparent;
			color: inherit;
			font: inherit;
			text-shadow: 0 0 1px var(--color-text);
			transition: background-color var(--ease-fast), border-color var(--ease-fast), transform var(--ease-fast);
		  }
		  .control:focus {
			border-color: var(--color-primary);
			box-shadow: 0 0 0 2px rgba(255,111,0,0.25);
		  }

		  .btn-primary {
			@apply .control;
			background-color: var(--color-primary);
			color: var(--color-bg);
			font-weight: 700;
			cursor: pointer;
			text-shadow: 0 0 3px var(--color-primary);
		  }
		  .btn-primary:hover { background-color: var(--color-primary-hover); }
		  .btn-primary:active { transform: translateY(1px); }

		  .status {
			display: inline-flex;
			align-items: center;
			gap: var(--space-xs);
			padding: var(--space-xs) var(--space-sm);
			border-radius: var(--radius-sm);
			font-size: 0.85rem;
			font-weight: 500;
			background-color: rgba(255,111,0,0.15);
			color: var(--color-primary);
		  }
		}

		@layer utilities {
		  .hidden { display: none !important; }
		  .text-primary { color: var(--color-primary); }
		  .flex { display: flex; }
		  .flex-col { flex-direction: column; }
		  .items-center { align-items: center; }
		  .justify-between { justify-content: space-between; }
		  .gap-xs { gap: var(--space-xs); }
		  .gap-sm { gap: var(--space-sm); }
		  .gap-md { gap: var(--space-md); }
		  .gap-lg { gap: var(--space-lg); }
		  .w-full { inline-size: 100%; }
		  .terminal-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
			gap: var(--space-lg);
		  }
		  .content-divider {
			block-size: 1px;
			background-color: var(--color-border);
			margin-block: var(--space-lg);
			inline-size: 100%;
		  }
		  .cli-output {
			border: 1px solid var(--color-border);
			border-radius: var(--radius-md);
			padding: var(--space-md);
			min-block-size: 180px;
			max-block-size: 500px;
			background: color-mix(in srgb, var(--color-bg) 90%, transparent);
			overflow: auto;
			white-space: pre-wrap;
			box-shadow: var(--shadow-sm);
		  }
		  .preview {
			border: 1px solid var(--color-border);
			border-radius: var(--radius-md);
			min-block-size: 300px;
			aspect-ratio: 16/9;
			background: var(--color-section-bg);
			box-shadow: var(--shadow-sm);
			transition: border-color var(--ease-fast), box-shadow var(--ease-fast);
		  }
		  .preview:focus-within {
			border-color: var(--color-primary);
			box-shadow: var(--shadow-md);
		  }
		}

		/* Responsive & feature queries */
		@media (max-width: 768px) {
		  html { font-size: clamp(0.75rem, 2vw, 0.875rem); }
		  main { padding-block: var(--space-md); gap: var(--space-lg); }
		  .terminal-grid { grid-template-columns: 1fr; }
		}
		@media (max-width: 640px) {
		  .command-area { display: flex; flex-direction: column; gap: var(--space-sm); }
		  .btn-primary { inline-size: 100%; }
		  .header-container { display: flex; flex-direction: column; gap: var(--space-sm); }
		}
		@media (min-height: 800px) {
		  .cli-output { min-block-size: 220px; }
		  .preview    { min-block-size: 350px; }
		}
		@media (prefers-color-scheme: dark) {
		  :root {
			--color-bg:            #00060f;
			--color-text:          #66ff99;
			--color-border:        #0f1116;
			--color-section-bg:    rgba(0,3,15,0.85);
			--shadow-sm:           0 1px 2px rgba(0,0,0,0.5);
			--shadow-md:           0 4px 6px rgba(0,0,0,0.7);
			--color-primary-hover: #ff9a33;
		  }
		}
		@media (prefers-reduced-motion: reduce) {
		  * { transition: none !important; animation: none !important; }
		}

	</style>
	<!--<link rel="stylesheet" href="web-cli.css">-->
</head>
<body>
	<h1>Terminal</h1>
	<input id="cli" placeholder="list | show N | open N" size="40" autofocus>
	<br>
	<button id="cliRun">Run</button>
	<pre id="cliOut"></pre>
	<iframe id="preview"></iframe>

	<script>
		const $=s=>document.querySelector(s);
		const preview=$('#preview');
		function runCli(){
			const cmd=$('#cli').value.trim();
			if(!cmd) return;
			$('#cliOut').textContent='…';
			fetch('?cli='+encodeURIComponent(cmd))
				.then(r=>r.json())
				.then(d=>{
					if(d.error){$('#cliOut').textContent='Error: '+d.error;preview.style.display='none';return;}
					if(d.list){$('#cliOut').textContent=d.list;preview.style.display='none';return;}
					if(d.code){$('#cliOut').textContent=d.code;preview.style.display='none';return;}
					if(d.url){
						window.open(d.url,'_blank');
						preview.src=d.url;
						preview.style.display='block';
						$('#cliOut').textContent='Rendered & opened: '+d.url;
						return;
					}
					$('#cliOut').textContent=JSON.stringify(d);
				})
				.catch(e=>$('#cliOut').textContent='Fetch error: '+e);
		}
		$('#cliRun').onclick=runCli;
		$('#cli').addEventListener('keydown',e=>e.key==='Enter'&&runCli());
	</script>
</body>
</html>

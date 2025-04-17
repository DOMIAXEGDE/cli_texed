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

			case 'open-blob':
				if (count($argv) < 1 || !ctype_digit($argv[0])) return ['error' => 'Usage: open-blob <index>'];
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
	<link rel="stylesheet" href="heaven.css">
</head>
<body>
	<h1>Terminal</h1>
	<input id="cli" placeholder="list | show N | open-blob N" size="40" autofocus>
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

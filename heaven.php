<?php
// vortextz-terminal.php — minimal CSS, full‑whitespace/TAB preservation,
// and “Run” now EXECUTES the code (HTML / JS) instead of only showing it.

error_reporting(E_ALL);
ini_set('display_errors', 1);
// ───────────────────────────────────────────────────────────────────
// CLI mode: simple commands (list, show, open-blob)
// ───────────────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    // Build command list
    $argv = $_SERVER['argv'];
    array_shift($argv); // remove script name
    $cmd = array_shift($argv) ?: 'help';
    $configFile = __DIR__ . '/vortextz-terminal-config.txt';
    $rawCmds = readCommandSequence($configFile);
    if (isset($rawCmds['error'])) {
        fwrite(STDERR, "Error: {$rawCmds['error']}\n");
        exit(1);
    }
    $commands = [];
    foreach ($rawCmds as $raw) {
        $p = parseCommand($raw);
        if (isset($p['error'])) continue;
        $res = processCode($p);
        $commands[] = ['parsed' => $p, 'result' => $res];
    }
    switch ($cmd) {
        case 'list':
            foreach ($commands as $i => $item) {
                $p   = $item['parsed'];
                $idx = $i + 1;
                $in  = "{$p['inputDirectory']}/{$p['inputFileBaseName']}.txt";
                $out = "{$p['outputDirectory']}/{$p['outputFileBaseName']}.txt";
                printf("%2d. %s -> %s (rows %d-%d, cols %d-%d)\n",
                    $idx, $in, $out,
                    $p['initialRow'], $p['finalRow'],
                    $p['initialColumn'], $p['finalColumn']
                );
            }
            exit(0);
        case 'show':
            if (count($argv) < 1 || !is_numeric($argv[0])) {
                fwrite(STDERR, "Usage: php heaven.php show <index>\n");
                exit(1);
            }
            $num = (int)$argv[0];
            if ($num < 1 || $num > count($commands)) {
                fwrite(STDERR, "Error: invalid index $num\n");
                exit(1);
            }
            $entry = $commands[$num - 1];
            if (isset($entry['result']['error'])) {
                fwrite(STDERR, "Error: {$entry['result']['error']}\n");
                exit(1);
            }
            echo $entry['result']['code'];
            exit(0);
        case 'open-blob':
            if (count($argv) < 1 || !is_numeric($argv[0])) {
                fwrite(STDERR, "Usage: php heaven.php open-blob <index>\n");
                exit(1);
            }
            $num = (int)$argv[0];
            if ($num < 1 || $num > count($commands)) {
                fwrite(STDERR, "Error: invalid index $num\n");
                exit(1);
            }
            $entry = $commands[$num - 1];
            if (isset($entry['result']['error'])) {
                fwrite(STDERR, "Error: {$entry['result']['error']}\n");
                exit(1);
            }
            // Prepare HTML for blob
            $p    = $entry['parsed'];
            $code = $entry['result']['code'];
            $lang = detectLanguage($code);
            if ($lang === 'javascript') {
                $escaped = json_encode($code);
                $title   = "JS Execution {$num}";
                $html    = "<!DOCTYPE html>\n<html>\n<head><title>{$title}</title></head>\n<body>\n<script>\ntry{eval({$escaped});}catch(e){console.error(e);}\n</script>\n</body>\n</html>";
            } elseif ($lang === 'html') {
                $html = $code;
            } else {
                $escaped = htmlspecialchars($code, ENT_QUOTES);
                $title   = "Code Preview {$num}";
                $html    = "<!DOCTYPE html>\n<html>\n<head><title>{$title}</title></head>\n<body>\n<pre>{$escaped}</pre>\n</body>\n</html>";
            }
            // Write to temp file and open
            $tmpFile = tempnam(sys_get_temp_dir(), 'heaven_') . '.html';
            file_put_contents($tmpFile, $html);
            $os = PHP_OS_FAMILY;
            if ($os === 'Windows') {
                pclose(popen("start \"\" " . escapeshellarg($tmpFile), 'r'));
            } elseif ($os === 'Darwin') {
                exec("open " . escapeshellarg($tmpFile));
            } else {
                exec("xdg-open " . escapeshellarg($tmpFile));
            }
            echo "Opened {$tmpFile}\n";
            exit(0);
        default:
            echo "Usage: php heaven.php <command>\n";
            echo "Commands:\n  list               List commands\n  show <index>       Show code for a command\n";
            echo "  open-blob <index>  Open code in browser as HTML\n";
            exit(0);
    }
}
// ───────────────────────────────────────────────────────────────────
// CLI mode: simple commands (list, show)
// ───────────────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    $argv = $_SERVER['argv'];
    array_shift($argv); // remove script name
    $cmd = array_shift($argv) ?: 'help';
    $configFile = __DIR__ . '/vortextz-terminal-config.txt';
    $rawCmds = readCommandSequence($configFile);
    if (isset($rawCmds['error'])) {
        fwrite(STDERR, "Error: {$rawCmds['error']}\n");
        exit(1);
    }
    $commands = [];
    foreach ($rawCmds as $raw) {
        $p = parseCommand($raw);
        if (isset($p['error'])) continue;
        $res = processCode($p);
        $commands[] = ['parsed' => $p, 'result' => $res];
    }
    switch ($cmd) {
        case 'list':
            foreach ($commands as $i => $item) {
                $p = $item['parsed'];
                $idx = $i + 1;
                $in  = "{$p['inputDirectory']}/{$p['inputFileBaseName']}.txt";
                $out = "{$p['outputDirectory']}/{$p['outputFileBaseName']}.txt";
                printf("%2d. %s -> %s (rows %d-%d, cols %d-%d)\n",
                    $idx, $in, $out,
                    $p['initialRow'], $p['finalRow'],
                    $p['initialColumn'], $p['finalColumn']
                );
            }
            exit(0);
        case 'show':
            $num = isset($argv[0]) ? (int)$argv[0] : 0;
            if ($num < 1 || $num > count($commands)) {
                fwrite(STDERR, "Usage: php heaven.php show <index>\n");
                exit(1);
            }
            $entry = $commands[$num - 1];
            if (isset($entry['result']['error'])) {
                fwrite(STDERR, "Error: {$entry['result']['error']}\n");
                exit(1);
            }
            echo $entry['result']['code'];
            exit(0);
        default:
            echo "Usage: php heaven.php <command>\n";
            echo "Commands:\n  list           List commands\n  show <index>   Show code for a command\n";
            exit(0);
    }
}

/* ─────────────────────────────────────────────────────────
   Helper Functions
───────────────────────────────────────────────────────── */

function readCommandSequence($filePath) {
	if (!file_exists($filePath)) {
		return ["error" => "Command file not found: $filePath"];
	}
	$lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$out   = [];
	foreach ($lines as $ln) {
		if ($ln !== '' && $ln[0] === '<') {		// keep leading whitespace
			$out[] = substr($ln, 1);			// strip “<”
		}
	}
	return $out;
}

function parseCommand($cmd) {
	$parts = explode('.', $cmd, 7);				// never trim → keep spaces
	if (count($parts) < 7) return ["error"=>"Invalid command: $cmd"];
	[$id,$colR,$rowR,$inBase,$inDir,$outDir,$last] = $parts;
	[$c1,$c2] = array_pad(explode(',', $colR), 2, PHP_INT_MAX);
	[$r1,$r2] = array_pad(explode(',', $rowR), 2, PHP_INT_MAX);
	$outBase  = preg_replace('/\(<\>\)$/', '', $last);

	return [
		"commandId"=>$id,"initialColumn"=>(int)$c1,"finalColumn"=>(int)$c2,
		"initialRow"=>(int)$r1,"finalRow"=>(int)$r2,
		"inputFileBaseName"=>$inBase,"inputDirectory"=>$inDir,
		"outputDirectory"=>$outDir,"outputFileBaseName"=>$outBase
	];
}

function processCode($p) {
	$src = "{$p['inputDirectory']}/{$p['inputFileBaseName']}.txt";
	if (!file_exists($src)) return ["error"=>"File not found: $src"];

	/* read EXACT bytes; keep TABs, spaces, newlines */
	$raw = file($src);
	$code = [];

	/* numbered‑pair format? */
	if (isset($raw[0]) && ctype_digit(trim($raw[0]))) {
		for ($i = 1; $i < count($raw); $i += 2) $code[] = $raw[$i];
	} else {
		$code = $raw;
	}

	$s = max(0, $p['initialRow'] - 1);
	$e = min(count($code) - 1, $p['finalRow'] - 1);
	$out = [];

	for ($i = $s; $i <= $e; $i++) {
		$ln = $code[$i];						// includes newline
		if ($p['initialColumn'] > 0 || $p['finalColumn'] < PHP_INT_MAX) {
			$st  = max(0, $p['initialColumn'] - 1);
			$len = $p['finalColumn'] - $st;
			$ln  = substr($ln, $st, $len);
		}
		$out[] = $ln;
	}
	$txt = implode('', $out);

	/* optional write‑out */
	if ($p['outputDirectory'] && $p['outputFileBaseName']) {
		$dst = "{$p['outputDirectory']}/{$p['outputFileBaseName']}.txt";
		if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0777, true);
		file_put_contents($dst, $txt);
	}
	return ["success"=>true,"code"=>$txt,"command"=>$p];
}

function detectLanguage($code) {
	$h = ltrim($code);
	if (preg_match('/^<(?:!DOCTYPE|html|head|body)/i',                           $h)) return 'html';
	if (preg_match('/^(?:function|var|let|const|import|export|class|document\.)/i',$h)) return 'javascript';
	return 'plaintext';
}
function uid() { return 'c_'.uniqid(); }

/* ─────────────────────────────────────────────────────────
   Render
───────────────────────────────────────────────────────── */
function renderCommands($cfgFile) {
	$cmds = readCommandSequence($cfgFile);
	if (isset($cmds['error'])) {
		echo "<p style='color:#c00'>".htmlspecialchars($cmds['error'])."</p>";
		return;
	}
	$q = strtolower(trim($_GET['query'] ?? ''));
	$blocks = $index = [];

	foreach ($cmds as $raw) {
		$p = parseCommand($raw);
		if (isset($p['error'])) continue;
		if ($q && strpos(strtolower($p['commandId']), $q) === false &&
		          strpos(strtolower($p['inputFileBaseName']), $q) === false) continue;

		$res = processCode($p);
		if (isset($res['error'])) continue;

		$id  = uid();
		$hdr = "<{$p['commandId']} {$p['initialColumn']},{$p['finalColumn']} | ".
		       "{$p['initialRow']},{$p['finalRow']} | ".
		       "{$p['inputFileBaseName']}.txt → {$p['outputFileBaseName']}.txt";

		$blocks[] = ['id'=>$id,'header'=>$hdr,'code'=>$res['code'],
		             'lang'=>detectLanguage($res['code'])];
		$index[]  = ['id'=>$id,'desc'=>$p['inputFileBaseName']];
	}

	/* simple nav */
	echo "<ul>";
	foreach ($index as $it) printf("<li><a href='#%s'>%s</a></li>\n",
	                               $it['id'], htmlspecialchars($it['desc']));
	echo "</ul>";

	/* blocks */
	foreach ($blocks as $b) {
		printf(
			"<div id='%s'>
\t<h3>%s</h3>
\t<pre id='%s_pre' data-language='%s' style='overflow:auto'>%s</pre>
\t<button onclick=\"run('%s_pre')\">Run</button>
\t<button onclick=\"copyTxt('%s_pre')\">Copy</button>
\t<input id='tab_%s' placeholder='tab name'>
\t<div id='r_%s' style='display:none;color:green'></div>
</div>\n\n",
			$b['id'],
			htmlspecialchars($b['header']),
			$b['id'],
			$b['lang'],
			htmlspecialchars($b['code'], ENT_NOQUOTES),	// keep TABs
			$b['id'], $b['id'], $b['id'], $b['id']
		);
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Texed Terminal</title>
</head>
<body style="font-family:monospace">
	<h1>Texed Terminal</h1>
	<form><input name="query" size="40" placeholder="type help or filter"
		         value="<?php echo htmlspecialchars($_GET['query'] ?? ''); ?>"></form>

	<div id="help" style="display:none;border:1px solid #888;padding:8px">
		<p>Type a command ID or descriptor to filter.</p>
		<p>Type “help” to toggle this panel.</p>
	</div>

	<?php renderCommands('vortextz-terminal-config.txt'); ?>

	<hr>
	<button id="runAll">Run All</button>
	<button id="copyAll">Copy All</button>

	<script>
		const $ = s => document.querySelector(s);

		/* ── open executable tab ─────────────────────────── */
		function execTab(raw, lang, title) {
			/* decode helper */
			const htmlFromRaw = txt => URL.createObjectURL(new Blob([txt],{type:'text/html'}));

			/* HTML: write raw HTML directly */
			if (lang === 'html') {
				const win = window.open(htmlFromRaw(raw), '_blank');
				if (!win) alert('allow pop‑ups'); return;
				win.onload = () => win.document.title = title;
				return;
			}

			/* JavaScript: eval but also show source */
			if (lang === 'javascript') {
				const b64  = btoa(unescape(encodeURIComponent(raw)));
				const shell =
		`<!DOCTYPE html><html><head><title>${title}</title></head>
		<body style="font-family:monospace">
		<pre id="src" style="white-space:pre;overflow:auto;border:1px solid #ccc"></pre>
		<script>
		\tconst raw = decodeURIComponent(escape(atob('${b64}')));
		\tdocument.getElementById('src').textContent = raw;
		\ttry { eval(raw); } catch(e) { console.error(e); alert('JS error: '+e); }
		<\/script></body></html>`;
				const win  = window.open(htmlFromRaw(shell), '_blank');
				if (!win) alert('allow pop‑ups');
				return;
			}

			/* anything else: show in <pre> */
			const shell =
		`<!DOCTYPE html><html><head><title>${title}</title></head>
		<body style="font-family:monospace">
		<pre style="white-space:pre;overflow:auto">${raw.replace(/[&<>]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m]))}</pre>
		</body></html>`;
			window.open(htmlFromRaw(shell), '_blank');
		}

		/* ── single block actions ────────────────────────── */
		function run(preId) {
			const pre  = document.getElementById(preId);
			const code = pre.textContent;
			const lang = pre.getAttribute('data-language') || 'plaintext';
			const id   = preId.replace('_pre','');
			const tabN = document.getElementById('tab_'+id).value || id;
			execTab(code, lang, tabN);
			const r    = document.getElementById('r_'+id);
			r.textContent = 'opened';
			r.style.display = 'block';
			setTimeout(()=>r.style.display='none',1200);
		}
		function copyTxt(preId){
			const ta=document.createElement('textarea');
			ta.value=document.getElementById(preId).textContent;
			document.body.appendChild(ta); ta.select(); document.execCommand('copy');
			document.body.removeChild(ta); alert('copied');
		}

		/* ── batch buttons ───────────────────────────────── */
		$('#runAll').onclick  = () => document.querySelectorAll('pre[id$="_pre"]').forEach(p=>run(p.id));
		$('#copyAll').onclick = () => {
			let all=''; document.querySelectorAll('pre[id$="_pre"]').forEach(p=>{
				all+='// '+p.previousElementSibling.textContent+'\n'+p.textContent+'\n\n';
			});
			const ta=document.createElement('textarea'); ta.value=all;
			document.body.appendChild(ta); ta.select(); document.execCommand('copy');
			document.body.removeChild(ta); alert('all copied');
		};

		/* ── live filter / help ──────────────────────────── */
		$('input[name="query"]').onkeyup = e=>{
			const q=e.target.value.trim().toLowerCase();
			if (q==='help') { $('#help').style.display=$('#help').style.display==='none'?'block':'none'; return; }
			document.querySelectorAll('div[id^="c_"]').forEach(d=>{
				const h=d.querySelector('h3').textContent.toLowerCase();
				d.style.display = q && !h.includes(q) ? 'none' : 'block';
			});
		};
	</script>
</body>
</html>

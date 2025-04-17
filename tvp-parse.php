<?php
// tvp-parse.php
// TVP Parse - Text Code Parser with Two Conversion Modes
//
// Conversion Modes:
// 1. Remove Line Numbers (Expected Format → Plain Code)
//    Expected Format (each pair of lines: the first is the line number,
//    and the second is the actual code, which may include TAB characters):
//
//         1
//         <?php
//         2
//         $greeting = "Hello World!";
//         3
//         echo $greeting;
//         4
//         ...
//
// 2. Add Line Numbers (Plain Code → Expected Format)
//    Plain Code Example:
//
//         <?php
//         $greeting = "Hello World!";
//         echo $greeting;
//         ...
//
//    In the output, each non-empty line of code will be preceded on its own line by its corresponding line number.
// 
// In both conversion modes, TAB characters (and any other internal whitespace) will be preserved.

$output = "";
$raw_input = "";
$mode = "remove"; // default conversion mode

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_input = isset($_POST['raw_input']) ? $_POST['raw_input'] : "";
    $mode = isset($_POST['mode']) ? $_POST['mode'] : "remove";
    
    // Split the input into lines.
    $lines = explode("\n", $raw_input);
    
    // Remove only trailing newline/carriage return characters (preserve TABs and other leading whitespace).
    $clean_lines = [];
    foreach ($lines as $line) {
        // Use rtrim to remove CR and LF but keep left-side whitespace (like TABs)
        $line = rtrim($line, "\r\n");
        // Only add non-empty lines if needed (or keep all if you want blank lines preserved).
        if ($line !== "") {
            $clean_lines[] = $line;
        }
	//$clean_lines[] = $line;
    }
    
    if ($mode === "remove") {
        // Conversion mode: Expected Format → Plain Code.
        // If the first non-empty line is entirely numeric, assume alternating pattern.
        if (count($clean_lines) > 0 && ctype_digit($clean_lines[0])) {
            $parsed_lines = array();
            // Assume even-indexed lines (0, 2, 4, …) are line numbers
            // and odd-indexed lines (1, 3, 5, …) are actual code lines.
            for ($i = 1; $i < count($clean_lines); $i += 2) {
                $parsed_lines[] = $clean_lines[$i];
            }
            $output = implode("\n", $parsed_lines);
        } else {
            // If no line numbers are detected, assume the input is already plain code.
            $output = $raw_input;
        }
    } elseif ($mode === "add") {
        // Conversion mode: Plain Code → Expected Format.
        // For each non-empty line, output the line number followed by the code line.
        $expected = array();
        $lineNumber = 1;
        foreach ($clean_lines as $codeLine) {
            $expected[] = $lineNumber;
            $expected[] = $codeLine;
            $lineNumber++;
        }
        $output = implode("\n", $expected);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TVP Parse - Text Code Parser</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        textarea {
            width: 100%;
            height: 200px;
            font-family: monospace;
        }
        .container {
            margin-bottom: 20px;
        }
        select, button {
            padding: 10px;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <h1>TVP Parse - Text Code Parser</h1>
    <p>
        Paste your text code input below and choose the conversion mode.
    </p>
    <form method="post">
        <div class="container">
            <label for="mode">Conversion Mode:</label>
            <select id="mode" name="mode">
                <option value="remove" <?php if($mode=="remove") echo "selected"; ?>>Remove Line Numbers (Expected Format → Plain Code)</option>
                <option value="add" <?php if($mode=="add") echo "selected"; ?>>Add Line Numbers (Plain Code → Expected Format)</option>
            </select>
        </div>
        <div class="container">
            <label for="raw_input">Input:</label><br>
            <textarea id="raw_input" name="raw_input"><?php echo htmlspecialchars($raw_input); ?></textarea>
        </div>
        <div class="container">
            <button type="submit">Parse</button>
        </div>
    </form>
    <?php if (!empty($output)): ?>
        <div class="container">
            <label for="output">Output:</label><br>
            <textarea id="output" readonly><?php echo htmlspecialchars($output); ?></textarea>
        </div>
        <div class="container">
            <button type="button" onclick="copyOutput()">Copy Output</button>
        </div>
    <?php endif; ?>
    <script>
        function copyOutput() {
            var outputTextarea = document.getElementById("output");
            outputTextarea.select();
            document.execCommand("copy");
            alert("Parsed output copied to clipboard!");
        }
    </script>
</body>
</html>

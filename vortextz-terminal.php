<?php
// x1.php - Text Code Parser and Processor with Execute and Copy functionality
// Command format: 
//   command id.initial column,final column.initial row,final row.input_file_base_name.input_directory_path.output_directory_path.output_file_base_name(<>)
// 
// Example command (in vrotextz-terminal-config.txt):
//   <1>.<1,30000>.<1,30000>.1a.in.out.buffer_1
//
// The input text code file should be formatted with alternating line numbers and code lines.
// For example, for a file with file identifier "1a", the content (1a.txt) should be:
//
// 1
// <?php
// 2
// $greeting = "Hello World!";
// 3
// echo $greeting;
// 4
// ...
//

//$structure = "command id.initial column,final column.initial row,final row.input_file_base_name.input_directory_path.output_directory_path.output_file_base_name(<>)";
//echo "<p>" . $structure . "</p>";

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Reads the command sequence from the given file.
 *
 * @param string $filePath Path to the command file.
 * @return array Array of commands or an error.
 */
function readCommandSequence($filePath) {
    if (!file_exists($filePath)) {
        return ["error" => "Command file not found: $filePath"];
    }
    
    $commands = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Only process lines starting with "<"
        if (substr($line, 0, 1) == '<') {
            $commands[] = trim(substr($line, 1)); // Remove the leading '<'
        }
    }
    
    return $commands;
}

/**
 * Parses a command string.
 *
 * @param string $command The command string.
 * @return array Parsed command parameters or an error.
 */
function parseCommand($command) {
    $parts = explode('.', $command);
    if (count($parts) < 7) {
        return ["error" => "Invalid command format: $command"];
    }
    
    // Extract command parts
    $commandId = $parts[0];
    
    // Extract column range
    $columnRange = explode(',', $parts[1]);
    $initialColumn = isset($columnRange[0]) ? intval($columnRange[0]) : 0;
    $finalColumn = isset($columnRange[1]) ? intval($columnRange[1]) : PHP_INT_MAX;
    
    // Extract row range
    $rowRange = explode(',', $parts[2]);
    $initialRow = isset($rowRange[0]) ? intval($rowRange[0]) : 0;
    $finalRow = isset($rowRange[1]) ? intval($rowRange[1]) : PHP_INT_MAX;
    
    // Extract file information
    $inputFileBaseName = $parts[3];
    $inputDirectory = $parts[4];
    $outputDirectory = $parts[5];
    $lastPart = $parts[6];
    $outputFileBaseName = preg_replace('/\(<>\)$/', '', $lastPart);
    
    return [
        "commandId" => $commandId,
        "initialColumn" => $initialColumn,
        "finalColumn" => $finalColumn,
        "initialRow" => $initialRow,
        "finalRow" => $finalRow,
        "inputFileBaseName" => $inputFileBaseName,
        "inputDirectory" => $inputDirectory,
        "outputDirectory" => $outputDirectory,
        "outputFileBaseName" => $outputFileBaseName,
    ];
}

/**
 * Processes code based on the command.
 * 
 * Now it reads an input file where the code is formatted with alternating line numbers and code lines.
 *
 * @param array $command Parsed command parameters.
 * @return array Result of processing or an error.
 */
function processCode($command) {
    // Construct full input file path
    $inputFile = $command["inputDirectory"] . "/" . $command["inputFileBaseName"] . ".txt";
    
    if (!file_exists($inputFile)) {
        return ["error" => "Input file not found: $inputFile"];
    }
    
    // Read the input file (each line without newlines)
    $lines = file($inputFile, FILE_IGNORE_NEW_LINES);
    
    // The input file is expected to alternate: line number, then code line.
    // Build an array of actual code lines.
    $codeLines = [];
    foreach ($lines as $index => $line) {
        // Assume even-indexed lines (0,2,4,...) are line numbers, and odd-indexed lines are code.
        if ($index % 2 == 1) {
            $codeLines[] = $line;
        }
    }
    
    // Apply row filter on the code lines array.
    // Convert to 0-based indexing.
    $initialRow = max(0, $command["initialRow"] - 1);
    $finalRow = min(count($codeLines) - 1, $command["finalRow"] - 1);
    
    $filteredLines = [];
    for ($i = $initialRow; $i <= $finalRow && $i < count($codeLines); $i++) {
        $line = $codeLines[$i];
        
        // Apply column filter if needed
        if ($command["initialColumn"] > 0 || $command["finalColumn"] < PHP_INT_MAX) {
            $initialCol = max(0, $command["initialColumn"] - 1); // Convert to 0-based indexing
            $length = $command["finalColumn"] - $initialCol;
            $line = substr($line, $initialCol, $length);
        }
        $filteredLines[] = $line;
    }
    
    // Join the filtered lines to form the processed code.
    $processedCode = implode("\n", $filteredLines);
    
    // If output directory and output file base name are specified, save the processed code.
    if (!empty($command["outputDirectory"]) && !empty($command["outputFileBaseName"])) {
        $outputFile = $command["outputDirectory"] . "/" . $command["outputFileBaseName"] . ".txt";
        
        // Create output directory if it doesn't exist.
        if (!file_exists(dirname($outputFile))) {
            mkdir(dirname($outputFile), 0777, true);
        }
        
        file_put_contents($outputFile, $processedCode);
    }
    
    return [
        "success" => true,
        "code" => $processedCode,
        "command" => $command
    ];
}

/**
 * Simple language detection based on code patterns.
 *
 * @param string $code The processed code.
 * @return string Detected language.
 */
function detectLanguage($code) {
    $code = trim($code);
    
    if (preg_match('/^\s*<(!DOCTYPE|html|head|body)/i', $code)) {
        return 'html';
    }
    if (preg_match('/^\s*(function|var|let|const|import|export|class|document\.|window\.)/i', $code)) {
        return 'javascript';
    }
    if (preg_match('/^\s*(body|\.|\#|\@media|margin|padding|color|font)/i', $code)) {
        return 'css';
    }
    if (preg_match('/^\s*(<\?php|namespace|use\s+[\\\\\w]+|function\s+\w+\s*\()/i', $code)) {
        return 'php';
    }
    if (preg_match('/^\s*(import|from|def|class|if __name__|print\()/i', $code)) {
        return 'python';
    }
    
    return 'plaintext';
}

/**
 * Generates a unique ID for identifying code blocks.
 *
 * @return string Unique ID.
 */
function generateUniqueId() {
    return 'code_' . uniqid();
}

/**
 * Main function to process command sequence from a command file and generate HTML for code blocks.
 *
 * @param string $commandFilePath Path to the command file.
 */
function processCommandSequence($commandFilePath) {
    $commands = readCommandSequence($commandFilePath);
    
    if (isset($commands["error"])) {
        echo "Error: " . $commands["error"];
        return;
    }
    
    $codeBlocks = [];
    
    foreach ($commands as $commandStr) {
        $command = parseCommand($commandStr);
        
        if (isset($command["error"])) {
            echo "Error parsing command: " . $command["error"] . "<br>";
            continue;
        }
        
        $result = processCode($command);
        
        if (isset($result["error"])) {
            echo "Error processing code: " . $result["error"] . "<br>";
            continue;
        }
        
        // Format output as specified.
        $cmd = $command["commandId"];
        $cols = $command["initialColumn"] . "," . $command["finalColumn"];
        $rows = $command["initialRow"] . "," . $command["finalRow"];
        $inBase = $command["inputFileBaseName"];
        $inDir = $command["inputDirectory"];
        $outDir = $command["outputDirectory"];
        $outBase = $command["outputFileBaseName"];
        
        // Detect language.
        $language = detectLanguage($result["code"]);
        
        // Generate unique ID for this code block.
        $uniqueId = generateUniqueId();
        
        // Store the code block info.
        $codeBlocks[] = [
            'id' => $uniqueId,
            'code' => $result["code"],
            'language' => $language,
            'command' => "<$cmd>$cols.$rows.$inBase.$inDir.$outDir.$outBase"
        ];
        
        // Output the command header and corresponding code block.
        echo "<$cmd>$cols.$rows.$inBase.$inDir.$outDir.$outBase <\n\n";
        echo "\"\"\"" . "\n";
        echo '<div class="code-container">';
        echo '<textarea id="' . $uniqueId . '" class="code-textarea" data-language="' . $language . '" style="width:100%; white-space:nowrap;">' . htmlspecialchars($result["code"]) . '</textarea>';
        echo '<div class="button-container">';
        echo '<button class="execute-button" onclick="executeCode(\'' . $uniqueId . '\')">Execute in New Tab</button>';
        echo '<button class="copy-button" onclick="copyCode(\'' . $uniqueId . '\')">Copy</button>';
        echo '<input type="text" id="tab_name_' . $uniqueId . '" class="tab-name-input" placeholder="Tab name (optional)" title="Specify a name for the new tab">';
        echo '</div>';
        echo '<div id="result_' . $uniqueId . '" class="result-container"></div>';
        echo '</div>';
        echo "\"\"\"" . "\n\n";
        echo ">\n";
    }
    
    // Only output JavaScript if there are code blocks.
    if (!empty($codeBlocks)) {
        outputJavaScript($codeBlocks);
    }
}

/**
 * Outputs the JavaScript needed for execute and copy functionality.
 *
 * @param array $codeBlocks Array of code block info.
 */
function outputJavaScript($codeBlocks) {
    echo '<script>';
    echo '
document.addEventListener("DOMContentLoaded", function() {
    const batchButtons = document.createElement("div");
    batchButtons.className = "batch-buttons";
    batchButtons.innerHTML = `
        <button id="batch-execute" onclick="executeBatchCode()">Execute All Code in New Tabs</button>
        <button id="batch-copy" onclick="copyBatchCode()">Copy All Code</button>
        <input type="text" id="batch_tab_prefix" class="tab-name-input" placeholder="Tab name prefix (optional)" title="Specify a prefix for tab names">
    `;
    document.body.insertBefore(batchButtons, document.body.firstChild);
});

function executeCode(id) {
    const textarea = document.getElementById(id);
    const resultContainer = document.getElementById("result_" + id);
    const code = textarea.value;
    const language = textarea.getAttribute("data-language");
    const tabNameInput = document.getElementById("tab_name_" + id);
    const tabName = tabNameInput.value || "Code Execution " + id;
    
    resultContainer.innerHTML = "";
    
    try {
        if (language === "javascript") {
            const htmlContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${tabName}</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        .console { 
                            background: #f5f5f5; 
                            border: 1px solid #ddd; 
                            padding: 10px; 
                            margin-top: 20px;
                            font-family: monospace;
                            white-space: pre;
                            overflow: auto;
                            max-height: 300px;
                        }
                        .error { color: red; }
                        .source-code {
                            background: #f9f9f9;
                            border: 1px solid #ddd;
                            padding: 10px;
                            margin-top: 20px;
                            font-family: monospace;
                            white-space: pre;
                            overflow: auto;
                            max-height: 200px;
                        }
                    </style>
                </head>
                <body>
                    <h2>JavaScript Execution</h2>
                    <div id="output"></div>
                    <div class="console" id="console"></div>
                    <h3>Source Code:</h3>
                    <div class="source-code">${escapeHtml(code)}</div>
                    <script>
                        const consoleDiv = document.getElementById("console");
                        const originalConsole = {
                            log: console.log,
                            error: console.error,
                            warn: console.warn,
                            info: console.info
                        };
                        console.log = function() {
                            const args = Array.from(arguments);
                            consoleDiv.innerHTML += args.join(" ") + "\\n";
                            originalConsole.log.apply(console, arguments);
                        };
                        console.error = function() {
                            const args = Array.from(arguments);
                            consoleDiv.innerHTML += \'<span class="error">\' + args.join(" ") + \'</span>\\n\';
                            originalConsole.error.apply(console, arguments);
                        };
                        try {
                            consoleDiv.innerHTML = "// Console Output:\\n";
                            const result = eval(${JSON.stringify(code)});
                            if (result !== undefined) {
                                document.getElementById("output").innerHTML = 
                                    \'<div style="margin-bottom: 10px;"><strong>Return value:</strong> <span style="font-family: monospace;">\' + JSON.stringify(result) + \'</span></div>\';
                            }
                        } catch (error) {
                            console.error("Error:", error.message);
                        }
                    <\/script>
                </body>
                </html>
            `;
            
            openInNewTab(htmlContent, tabName);
        } else if (language === "html") {
            openInNewTab(code, tabName);
        } else if (language === "css") {
            const htmlContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${tabName}</title>
                    <style>
                        ${code}
                    </style>
                </head>
                <body>
                    <h2>CSS Preview</h2>
                    <p>This page shows the CSS code applied to a blank document.</p>
                    <h3>Source CSS:</h3>
                    <pre style="background: #f9f9f9; border: 1px solid #ddd; padding: 10px;">${escapeHtml(code)}</pre>
                </body>
                </html>
            `;
            openInNewTab(htmlContent, tabName);
        } else if (language === "php") {
            const htmlContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${tabName}</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        .message { background: #f5f5f5; border: 1px solid #ddd; padding: 15px; }
                    </style>
                </head>
                <body>
                    <h2>PHP Code</h2>
                    <div class="message">
                        <p>PHP is a server-side language and cannot be executed directly in the browser.</p>
                    </div>
                    <h3>Source Code:</h3>
                    <pre>${escapeHtml(code)}</pre>
                </body>
                </html>
            `;
            openInNewTab(htmlContent, tabName);
        } else if (language === "python") {
            const htmlContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${tabName}</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        .message { background: #f5f5f5; border: 1px solid #ddd; padding: 15px; }
                    </style>
                </head>
                <body>
                    <h2>Python Code</h2>
                    <div class="message">
                        <p>Python code is typically executed server-side.</p>
                    </div>
                    <h3>Source Code:</h3>
                    <pre>${escapeHtml(code)}</pre>
                </body>
                </html>
            `;
            openInNewTab(htmlContent, tabName);
        } else {
            const htmlContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${tabName}</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        .message { background: #f5f5f5; border: 1px solid #ddd; padding: 15px; }
                    </style>
                </head>
                <body>
                    <h2>Code Display</h2>
                    <div class="message">
                        <p>This language (${language}) is not directly executable in the browser.</p>
                    </div>
                    <h3>Source Code:</h3>
                    <pre>${escapeHtml(code)}</pre>
                </body>
                </html>
            `;
            openInNewTab(htmlContent, tabName);
        }
        
        resultContainer.innerHTML = "<div class=\'message\'>Code opened in a new tab.</div>";
        setTimeout(() => {
            resultContainer.innerHTML = "";
        }, 3000);
    } catch (error) {
        resultContainer.innerHTML = error.message;
    }
}

function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/\'/g, "&#039;");
}

function openInNewTab(content, tabName) {
    const blob = new Blob([content], {type: "text/html"});
    const url = URL.createObjectURL(blob);
    const newTab = window.open(url, "_blank");
    if (newTab) {
        newTab.document.title = tabName;
    } else {
        alert("Please allow pop-ups for this site to open code in new tabs.");
    }
}

function copyCode(id) {
    const textarea = document.getElementById(id);
    textarea.select();
    document.execCommand("copy");
    const copyButton = textarea.nextElementSibling.querySelector(".copy-button");
    const originalText = copyButton.textContent;
    copyButton.textContent = "Copied!";
    setTimeout(function() {
        copyButton.textContent = originalText;
    }, 1500);
}

function executeBatchCode() {
    const textareas = document.querySelectorAll(".code-textarea");
    const tabPrefix = document.getElementById("batch_tab_prefix").value || "Batch Code";
    let index = 1;
    textareas.forEach(textarea => {
        const tabName = tabPrefix + " " + index;
        document.getElementById("tab_name_" + textarea.id).value = tabName;
        executeCode(textarea.id);
        index++;
        setTimeout(() => {}, 300);
    });
}

function copyBatchCode() {
    const textareas = document.querySelectorAll(".code-textarea");
    let allCode = "";
    textareas.forEach(textarea => {
        const command = textarea.parentElement.parentElement.previousElementSibling.textContent;
        allCode += "// " + command + "\n";
        allCode += textarea.value + "\n\n";
    });
    const tempTextarea = document.createElement("textarea");
    tempTextarea.value = allCode;
    document.body.appendChild(tempTextarea);
    tempTextarea.select();
    document.execCommand("copy");
    document.body.removeChild(tempTextarea);
    const batchCopyButton = document.getElementById("batch-copy");
    const originalText = batchCopyButton.textContent;
    batchCopyButton.textContent = "All Code Copied!";
    setTimeout(function() {
        batchCopyButton.textContent = originalText;
    }, 1500);
}
';
    echo '</script>';
}

// Determine execution mode: command-line or web server.
if (php_sapi_name() === 'cli') {
    $commandFile = 'vortextz-terminal-config.txt';
    if (isset($argv[1])) {
        $commandFile = $argv[1];
    }
    processCommandSequence($commandFile);
} else {
    echo "<!DOCTYPE html>\n";
    echo "<html lang='en'>\n";
    echo "<head>\n";
    echo "    <meta charset='UTF-8'>\n";
    echo "    <meta name='viewport' content='width=device-width, initial-scale=1.0'>\n";
    echo "    <title>Text Code Parser and Executor</title>\n";
    echo "    <style>\n";
    echo "        body { 
                    font-family: monospace; 
                    padding: 20px;
                    max-width: 1200px;
                    margin: 0 auto;
                }
                .batch-buttons {
                    position: sticky;
                    top: 0;
                    background: #f0f0f0;
                    padding: 10px;
                    margin-bottom: 20px;
                    z-index: 100;
                    border-bottom: 1px solid #ccc;
                    display: flex;
                    gap: 10px;
                    align-items: center;
                    flex-wrap: wrap;
                }
                .code-container {
                    margin-bottom: 20px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    overflow: hidden;
                }
                .code-textarea {
                    width: 100%;
                    min-height: 100px;
                    font-family: monospace;
                    padding: 10px;
                    border: none;
                    border-bottom: 1px solid #ddd;
                    box-sizing: border-box;
                    white-space: pre;
                    resize: vertical;
                }
                .button-container {
                    display: flex;
                    gap: 10px;
                    padding: 10px;
                    background: #f5f5f5;
                    align-items: center;
                    flex-wrap: wrap;
                }
                button {
                    padding: 8px 12px;
                    background: #4285f4;
                    color: white;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                    font-weight: bold;
                }
                button:hover {
                    background: #3367d6;
                }
                .result-container {
                    padding: 10px;
                    background: #f9f9f9;
                    border-top: 1px solid #ddd;
                    display: none;
                }
                .result-container:not(:empty) {
                    display: block;
                }
                .error {
                    color: #d32f2f;
                    margin: 0;
                }
                .message {
                    color: #666;
                    font-style: italic;
                }
                pre {
                    margin: 0;
                    white-space: pre-wrap;
                }
                #batch-execute {
                    background: #0f9d58;
                }
                #batch-execute:hover {
                    background: #0b8043;
                }
                #batch-copy {
                    background: #f4b400;
                    color: #333;
                }
                #batch-copy:hover {
                    background: #f09300;
                }
                .tab-name-input {
                    padding: 8px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    font-family: monospace;
                    min-width: 200px;
                }
        \n";
    echo "    </style>\n";
    echo "</head>\n";
    echo "<body>\n";
    
    $commandFile = 'vortextz-terminal-config.txt';
    processCommandSequence($commandFile);
    
    echo "</body>\n";
    echo "</html>";
}
?>
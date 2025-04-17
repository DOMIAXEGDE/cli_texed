<?php

/**
 * Class TuringNavigator
 * Handles loading data, managing state, and rendering components for a
 * context-aware navigation menu with optional Turing tape visualization.
 * Supports AJAX updates for context switching.
 */
class TuringNavigator {
    private $state;      // User-defined state (e.g., 'true'/'false')
    private $tape;       // Array of items for the current context
    private $head;       // Current position/index in the tape/list
    private $contextId;  // ID of the currently active context/tab
    private $tabs;       // Array of available tabs/contexts
    private $error;      // Stores the last error message
    private $config;     // Application configuration from config.json

    /**
     * Constructor: Initializes configuration, state, tabs, context, and tape data.
     */
    public function __construct() {
        // Load config first as it might be needed early
        $this->loadConfig();
        $this->error = null; // Initialize error status

        // Determine state from request (GET or POST for AJAX compatibility)
        $this->state = isset($_REQUEST['user']) ? $this->sanitizeInput($_REQUEST['user']) : 'false';

        // Load available navigation tabs
        $this->tabs = $this->loadTabs();

        // Determine the active context ID from request, fall back gracefully
        $defaultContextId = !empty($this->tabs) ? $this->tabs[0]['id'] : 1; // Default to first tab ID or 1
        $requestedContextId = isset($_REQUEST['context']) ? intval($_REQUEST['context']) : $defaultContextId;

        // Validate if the requested context ID actually exists in the loaded tabs
        $validContext = false;
        foreach ($this->tabs as $tab) {
            if ($tab['id'] == $requestedContextId) {
                $validContext = true;
                break;
            }
        }
        // Use the validated context ID or the default if the requested one was invalid
        $this->contextId = $validContext ? $requestedContextId : $defaultContextId;

        // Determine head position, ensuring it's a non-negative integer
        // Default head to 0 if changing context, otherwise use value from request
        $isContextChange = isset($_REQUEST['context']) && $requestedContextId != (isset($_REQUEST['prev_context']) ? intval($_REQUEST['prev_context']) : $this->contextId); // Simple check if context differs
        $defaultHead = $isContextChange ? 0 : (isset($_REQUEST['head']) ? max(0, intval($_REQUEST['head'])) : 0);
        $this->head = isset($_REQUEST['head']) ? max(0, intval($_REQUEST['head'])) : $defaultHead;


        // Load the tape data specific to the determined context
        $this->tape = $this->loadTape($this->contextId);

        // Adjust head position to be within the bounds of the loaded tape
        if (!empty($this->tape)) {
             $maxHead = count($this->tape) - 1;
             $this->head = min($this->head, $maxHead); // Cannot exceed max index
             $this->head = max(0, $this->head);       // Cannot be negative
        } else {
             $this->head = 0; // Reset head if tape is empty for this context
        }
    }

    /**
     * Loads configuration from config.json, providing default values.
     */
    private function loadConfig() {
        $configFile = 'config.json';
        $defaultConfig = [
            'max_buttons'   => 100,   // Max items per 0_*.txt file
            'debug_mode'    => false,  // Show debug panel (true/false)
            'cache_timeout' => 120    // Cache duration for tape data in seconds
        ];

        if (file_exists($configFile)) {
            $contents = @file_get_contents($configFile); // Suppress warnings on read failure
            $decoded = $contents ? json_decode($contents, true) : null; // Decode if contents exist
            if (is_array($decoded)) {
                // Merge defaults with loaded config; loaded values overwrite defaults
                $this->config = array_merge($defaultConfig, $decoded);
            } else {
                $this->config = $defaultConfig; // Use defaults if file invalid/empty
            }
        } else {
            $this->config = $defaultConfig; // Use defaults if file missing
        }
    }

    /**
     * Sanitizes user input string.
     * @param string $input Raw input string.
     * @return string Sanitized string.
     */
    private function sanitizeInput($input) {
        return htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Loads tab definitions from nav-tabs.txt.
     * Format per line: ID:Tab Name (e.g., 1:General Tools)
     * @return array Array of tab definitions [['id' => int, 'name' => string], ...].
     */
    private function loadTabs() {
        $tabsFile = 'nav-tabs.txt';
        $tabs = [];
        if (file_exists($tabsFile)) {
            $lines = file($tabsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $parts = explode(':', trim($line), 2); // Split into max 2 parts
                // Validate format: numeric positive ID and a name must exist
                if (count($parts) === 2 && is_numeric($parts[0]) && intval($parts[0]) > 0 && trim($parts[1]) !== '') {
                    $tabs[] = [
                        'id'   => intval($parts[0]),
                        'name' => trim($parts[1])
                    ];
                } elseif ($this->config['debug_mode']) {
                     error_log("Malformed line in $tabsFile: $line"); // Log invalid lines if debugging
                }
            }
        } else {
             $this->error = "Navigation tabs file ('$tabsFile') not found.";
             // Consider providing a single default tab:
             // $tabs = [['id' => 1, 'name' => 'Default Context']];
        }
        // Sort tabs by ID for consistent display order
        usort($tabs, function($a, $b) {
            return $a['id'] <=> $b['id']; // spaceship operator for comparison
        });
        return $tabs;
    }

    /**
     * Loads tape/list data for a specific context ID from 0_ID.txt.
     * Uses caching to improve performance.
     * @param int $contextId The ID of the context to load.
     * @return array Array of tape/list items for the context.
     */
    private function loadTape($contextId) {
        $buttonFile = "buttons_{$contextId}.txt";
        $cacheFile = "tape_cache_{$contextId}.json"; // Context-specific cache file

        // Attempt to load from cache first
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $this->config['cache_timeout'])) {
            $cachedData = @file_get_contents($cacheFile);
            $decodedTape = $cachedData ? json_decode($cachedData, true) : null;
             // Check if cache decoding was successful and returned an array
             if (is_array($decodedTape)) {
                return $decodedTape; // Return cached data
             }
             // If cache invalid, proceed to load from the source file
        }

        // Load from the primary source file (0_*.txt)
        if (!file_exists($buttonFile)) {
            $this->error = "Button configuration file ('{$buttonFile}') for context ID {$contextId} not found.";
            return []; // Return empty array if source file missing
        }

        $tape = file($buttonFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Enforce maximum button/item limit from config
        if (count($tape) > $this->config['max_buttons']) {
            $this->error = "Maximum item limit ({$this->config['max_buttons']}) exceeded for '{$buttonFile}'. Truncating list.";
            $tape = array_slice($tape, 0, $this->config['max_buttons']); // Truncate the array
        }

        // Attempt to write the freshly loaded data to cache
         $encodedTape = json_encode($tape);
         if ($encodedTape !== false) { // Check if JSON encoding was successful
             @file_put_contents($cacheFile, $encodedTape); // Suppress errors on write failure
         }

        return $tape;
    }

    // --- Getters for state properties ---
    public function getCurrentState() { return $this->state; }
    public function getCurrentContextId() { return $this->contextId; }
    public function getCurrentHead() { return $this->head; }
    public function getTape() { return $this->tape; }
    public function getConfig($key = null) { return $key ? ($this->config[$key] ?? null) : $this->config; }


    /**
     * Generates URL query string parameters, preserving relevant state.
     * Used for constructing URLs in tabs and potentially for JS History API.
     * @param array $params Parameters to override or add.
     * @return string URL query string (e.g., "context=1&head=0&user=false").
     */
    private function generateUrlParams($params = []) {
        // Base parameters from current state
        $currentParams = [
            'context' => $this->contextId,
            'head'    => $this->head,
            'user'    => $this->state
        ];

        // Determine if this is a context switch to reset head
        $newContextId = $params['context'] ?? $this->contextId;
        $isContextSwitch = $newContextId != $this->contextId;

        // Reset head to 0 on context switch unless explicitly provided otherwise
        if ($isContextSwitch && !isset($params['head'])) {
             $currentParams['head'] = 0;
        }

        // Merge provided parameters, overriding defaults
        $finalParams = array_merge($currentParams, $params);
        return http_build_query($finalParams);
    }

    /**
     * Renders the HTML for the context navigation tabs.
     * Includes data attributes for JavaScript AJAX handling.
     * @return string HTML output for the tabs.
     */
    public function renderTabs() {
        // Handle case where tab definition file itself was missing
         if (empty($this->tabs) && $this->error && strpos($this->error, 'Navigation tabs file') !== false) {
             return '<div class="error-message">' . htmlspecialchars($this->error) . '</div>';
         }
         // Handle case where file exists but is empty or contains no valid tabs
          if (empty($this->tabs)) {
             return '<div class="error-message">No navigation tabs defined or nav-tabs.txt is empty/invalid.</div>';
         }

        $html = '<div class="context-tabs">';
        foreach ($this->tabs as $tab) {
            $isActive = ($tab['id'] === $this->contextId);
            // Generate URL parameters for the target state when this tab is clicked
            $urlParams = $this->generateUrlParams(['context' => $tab['id']]); // Head automatically resets here

            $html .= sprintf(
                // href contains fallback URL, data- attributes used by JS for AJAX
                '<a href="?%s" class="tab-item%s" data-context-id="%d" data-target-params="%s">%s</a>',
                htmlspecialchars($urlParams, ENT_QUOTES, 'UTF-8'), // Fallback href
                $isActive ? ' active' : '',                         // Active class for styling
                $tab['id'],                                         // Context ID for JS
                htmlspecialchars($urlParams, ENT_QUOTES, 'UTF-8'), // Target URL params for History API
                htmlspecialchars($tab['name'], ENT_QUOTES, 'UTF-8') // Display name
            );
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders the HTML for the Turing tape visualization (optional).
     * @return string HTML output for the tape.
     */
    public function renderTape() {
        // If tape loading failed specifically for this context, show error
         if (empty($this->tape) && $this->error && strpos($this->error, 'Button configuration file') !== false) {
             return '<div class="tape-container"><div class="error-message">' . htmlspecialchars($this->error) . '</div></div>';
         }
         // If tape is simply empty (no error), show a message
        if (empty($this->tape)) {
            return '<div class="tape-container"><div class="message-text" style="text-align:center; padding: 20px; opacity: 0.7;">(No tape items for this context)</div></div>';
        }

        // Calculate the horizontal translation based on the head position
        $cellWidthAndGap = 42; // Approx width of cell + gap
        $translateX = -$this->head * $cellWidthAndGap;

        // Build the HTML string
        $html = '<div class="tape-container">';
        $html .= '<div class="tape" style="transform: translateX(' . $translateX . 'px)">';
        foreach ($this->tape as $index => $cell) {
            // Extract label/link part (before first colon)
            $parts = explode(':', $cell, 2);
            $label = $parts[0];
            // Derive a display name (e.g., from filename or URL part)
            $filenameWithoutExt = pathinfo($label, PATHINFO_FILENAME);
            $rawName = !empty($filenameWithoutExt) ? $filenameWithoutExt : $label;
            $displayName = ucwords(str_replace(['_', '-'], ' ', $rawName));
            $isActive = ($index === $this->head);

            $html .= sprintf(
                '<div class="cell%s" data-index="%d" title="%s">%s</div>',
                $isActive ? ' active' : '',                       // Active class
                $index,                                           // Data index attribute
                htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'), // Full name tooltip
                htmlspecialchars(mb_substr($displayName, 0, 1), ENT_QUOTES, 'UTF-8') // First letter display
            );
        }
        $html .= '</div></div>'; // Close tape and container
        return $html;
    }

    /**
     * Renders the HTML for the searchable list menu.
     * @return string HTML output for the list menu.
     */
	/**
     * Renders the HTML for the searchable list menu.
     * ALL links generated will open in a new tab (_blank).
     * @return string HTML output for the list menu.
     */
    public function renderListMenu() {
        $listMenuContainerHtml = '<div class="list-menu-container">';

        // Handle error case where button file was missing
        if (empty($this->tape) && $this->error && strpos($this->error, 'Button configuration file') !== false) {
            $listMenuContainerHtml .= '<div class="error-message">' . htmlspecialchars($this->error) . '</div>';
        }
        // Handle case where tape is simply empty for this context
        elseif (empty($this->tape)) {
            $listMenuContainerHtml .= '<input type="text" id="menuSearch" placeholder="Search..." disabled/>';
            $listMenuContainerHtml .= '<ul id="menuList"><li class="message-text" style="text-align: center; padding: 20px; opacity: 0.7;">No items in this context.</li></ul>';
        }
        // Render the list if tape has items
        else {
            $listMenuContainerHtml .= '<input type="text" id="menuSearch" placeholder="Search current list (' . count($this->tape) . ' items)..." autocomplete="off" />';
            $listMenuContainerHtml .= '<ul id="menuList">';
            foreach ($this->tape as $index => $cell) {
                // Parse item: LinkTarget:Description (Description is optional)
                $parts = explode(':', $cell, 2);
                $linkTarget = trim($parts[0]);
                $description = isset($parts[1]) ? trim($parts[1]) : ''; // Use description if provided

                // Determine display name: Use description if available, otherwise derive from linkTarget
                if (empty($description)) {
                    $filenameWithoutExt = pathinfo($linkTarget, PATHINFO_FILENAME);
                    $rawName = !empty($filenameWithoutExt) ? $filenameWithoutExt : $linkTarget; // Use filename or full link
                    $displayName = ucwords(str_replace(['_', '-'], ' ', $rawName)); // Format nicely
                } else {
                    $displayName = $description; // Use provided description
                }

                // --- MODIFICATION HERE ---
                // Always open menu links in a new blank tab for security and user preference
                $targetAttribute = 'target="_blank" rel="noopener noreferrer"';
                // --- END MODIFICATION ---

                $listMenuContainerHtml .= sprintf(
                    '<li data-index="%d"><a href="%s" %s><span class="item-index">%d</span> %s</a></li>',
                    $index,                                             // Data index
                    htmlspecialchars($linkTarget, ENT_QUOTES, 'UTF-8'),  // The link
                    $targetAttribute,                                   // Target attribute (now always _blank)
                    $index,                                             // Display index number
                    htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') // Display name/description
                );
            }
            $listMenuContainerHtml .= '</ul>';
        }

        $listMenuContainerHtml .= '</div>'; // Close list-menu-container
        return $listMenuContainerHtml;
    }

    /**
     * Renders the HTML for the debug panel if enabled in config.
     * @return string HTML output for the debug panel, or empty string.
     */
    public function renderDebugPanel() {
        if (!$this->config['debug_mode']) {
            return ''; // Return empty if debug mode is off
        }

        $html = '<div class="debug-panel visible">'; // Add 'visible' class to ensure display
        $html .= '<strong>Debug Info (AJAX Enabled):</strong><br>';
        $html .= 'State: ' . htmlspecialchars($this->state) . '<br>';
        $html .= 'Head Position: ' . $this->head . '<br>';
        $html .= 'Current Context ID: ' . $this->contextId . '<br>';
        $html .= 'Tape Source: 0_' . $this->contextId . '.txt<br>';
        $html .= 'Tape Length: ' . count($this->tape) . '<br>';
        $html .= 'Tabs Loaded: ' . count($this->tabs) . '<br>';
            if ($this->error) {
                $html .= 'Last Error: <span style="color: var(--error-color);">' . htmlspecialchars($this->error) . '</span><br>';
            }
        $html .= 'PHP Version: ' . phpversion() . '<br>';
        $html .= 'Cache Timeout: ' . $this->config['cache_timeout'] . 's<br>';
        // Indicate if the current rendering context is via AJAX
        if (isset($_REQUEST['ajax']) && $_REQUEST['ajax'] == '1') {
             $html .= 'Request Type: AJAX<br>';
        } else {
             $html .= 'Request Type: Full Page<br>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Handles AJAX requests. If detected, outputs JSON response and exits.
     * Otherwise, does nothing, allowing normal page rendering to proceed.
     */
    public function handleAjaxRequest() {
        // Detect AJAX request (simple check via query parameter)
        $isAjax = isset($_REQUEST['ajax']) && $_REQUEST['ajax'] == '1';

        if ($isAjax) {
            // Set response header to JSON
            header('Content-Type: application/json');

            // Generate the HTML parts needed for the dynamic update
            $menuHtml = $this->renderListMenu();
            $tapeHtml = $this->renderTape(); // Render tape too if it's in the dynamic wrapper
            $debugHtml = $this->renderDebugPanel(); // Regenerate debug info for current AJAX state

            // Prepare JSON response payload
            $response = [
                'success' => true, // Indicate successful processing
                'menuHtml' => $menuHtml,
                'tapeHtml' => $tapeHtml,
                'debugHtml' => $debugHtml,
                'newHead' => $this->getCurrentHead() // Send back the potentially adjusted head position
            ];

            // Output JSON and stop script execution
            echo json_encode($response);
            exit;
        }
        // If not an AJAX request, method finishes, and script continues below.
    }
}

// --- Main Script Execution ---

// Instantiate the navigator (handles constructor logic: config, state, tabs, context, tape)
$navigator = new TuringNavigator();

// Check for and handle AJAX requests *before* any HTML output
$navigator->handleAjaxRequest();

// If the script hasn't exited due to AJAX, proceed to render the full HTML page:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Texed ::: Programming</title>
    <style>
        /* --- Root Variables --- */
        :root {
            --transition-speed: 0.3s;             /* Standard transition speed */
            --slow-transition-speed: 0.6s;        /* Slower speed for AJAX fades */
            --state-color: #00f0ff;               /* Accent color (cyan) */
            --error-color: #ff4444;               /* Error color (red) */
            --background-color: #1a1a1a;          /* Main background */
            --text-color: #e0e0e0;                /* Main text color (slightly off-white) */
            --primary-border-color: var(--state-color);
            --secondary-border-color: #444;       /* Borders for less important elements */
            --input-background: #333;             /* Background for inputs */
            --hover-background: #282828;          /* Background on hover */
            --active-tab-background: var(--state-color); /* Active tab background */
            --active-tab-text: var(--background-color);   /* Active tab text color */
            --inactive-tab-background: var(--input-background); /* Inactive tab background */
            --inactive-tab-text: var(--state-color);        /* Inactive tab text */
            --tab-border-color: var(--primary-border-color);
            --paragraph-background: rgba(0, 240, 255, 0.05); /* Subtle background for info text */
            --image-shadow-color: rgba(0, 240, 255, 0.3);   /* Shadow for hover image */
            --content-opacity: 1;                 /* Default opacity for dynamic content */
            --content-opacity-loading: 0.3;       /* Opacity during AJAX load */
        }

        /* --- General Body --- */
        body {
            background: var(--background-color);
            color: var(--text-color);
            font-family: 'Courier New', monospace;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }

        /* --- Loading Bar (Top of Page) --- */
        .loading-bar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--state-color);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.3s ease-out, opacity 0.3s 0.3s; /* Animate transform, then fade opacity */
            z-index: 1000;
            opacity: 1;
        }
        .loading-bar.hidden {
            transform: scaleX(1); /* Ensure it reaches full width before hiding */
            opacity: 0;
        }

        /* --- Context Tabs Styles --- */
        .context-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 5px; /* Space between tabs */
            margin: 20px auto;
            max-width: 600px; /* Consistent width with menu */
            padding-bottom: 10px;
            border-bottom: 1px solid var(--secondary-border-color);
        }
        .tab-item {
            padding: 8px 15px;
            text-decoration: none;
            border: 1px solid var(--tab-border-color);
            border-radius: 4px 4px 0 0; /* Rounded top corners */
            background-color: var(--inactive-tab-background);
            color: var(--inactive-tab-text);
            font-size: 0.9em;
            transition: background-color var(--transition-speed), color var(--transition-speed);
            white-space: nowrap; /* Prevent tab names from wrapping */
            cursor: pointer; /* Indicate clickable for JS */
        }
        .tab-item:hover {
            background-color: var(--hover-background);
            color: var(--text-color);
        }
        .tab-item.active {
            background-color: var(--active-tab-background);
            color: var(--active-tab-text);
            border-bottom-color: var(--active-tab-background); /* Visually connect to content */
            font-weight: bold;
            cursor: default; /* Not clickable when active */
        }

        /* --- AJAX Transition Styles --- */
        /* Wrapper for dynamically updated content */
        #dynamicContentWrapper {
             transition: opacity var(--slow-transition-speed) ease-in-out; /* Slow fade transition */
             opacity: var(--content-opacity); /* Controlled via JS */
             position: relative; /* For potential overlays like spinners */
             min-height: 100px; /* Prevent collapsing during load */
        }
         /* Style applied during AJAX load */
         #dynamicContentWrapper.loading {
             opacity: var(--content-opacity-loading);
             /* Optional: Add a subtle visual indicator */
             /* outline: 1px dashed rgba(0, 240, 255, 0.2); */
         }

        /* --- Turing Tape Styles --- */
         .tape-container {
             position: relative;
             width: 100%;
             overflow: hidden; /* Hide overflowing tape cells */
             margin: 20px 0;
         }
         .tape {
             display: flex;
             justify-content: center; /* Center tape cells */
             align-items: center;
             gap: 2px; /* Small gap between cells */
             padding: 20px 0; /* Padding top/bottom */
             transition: transform var(--transition-speed); /* Smooth horizontal scroll */
             width: max-content; /* Ensure tape width fits content */
             margin: 0 auto; /* Center the tape itself if container is wider */
         }
         .cell {
             min-width: 40px;
             height: 40px;
             border: 1px solid var(--primary-border-color);
             display: flex;
             justify-content: center;
             align-items: center;
             transition: all var(--transition-speed); /* Smooth transitions for active state */
             position: relative; /* For pseudo-element positioning */
             color: var(--text-color);
             background-color: var(--background-color);
             font-weight: bold;
         }
         .cell.active {
             background: gold;
             color: var(--background-color); /* Ensure text visibility on gold */
             box-shadow: 0 0 10px var(--state-color);
             transform: scale(1.1); /* Slightly enlarge active cell */
             z-index: 1; /* Ensure active cell is above others */
         }
         /* Index number below cell */
         .cell::after {
             content: attr(data-index);
             position: absolute;
             bottom: -20px;
             left: 50%;
             transform: translateX(-50%);
             font-size: 0.8em;
             opacity: 0.6;
         }

        /* --- List Menu Styles --- */
         .list-menu-container {
             max-width: 600px;
             margin: 0 auto 20px auto; /* Center and provide bottom margin */
         }
         #menuSearch {
             width: 100%;
             box-sizing: border-box; /* Include padding/border in width */
             padding: 10px;
             margin-bottom: 10px;
             border: 1px solid var(--primary-border-color);
             background: var(--input-background);
             color: var(--text-color);
             font-size: 1em;
             border-radius: 5px;
         }
         #menuSearch:focus {
             outline: none;
             box-shadow: 0 0 8px rgba(0, 240, 255, 0.5);
         }
         #menuList {
             list-style: none;
             padding: 0;
             margin: 0;
             max-height: 400px; /* Limit height and enable scroll */
             overflow-y: auto;
             border: 1px solid var(--primary-border-color);
             border-radius: 5px;
             opacity: 1; /* Default opacity */
         }
         /* Style for scrollbar (Webkit browsers) */
         #menuList::-webkit-scrollbar {
             width: 8px;
         }
         #menuList::-webkit-scrollbar-track {
             background: var(--input-background);
             border-radius: 4px;
         }
         #menuList::-webkit-scrollbar-thumb {
             background-color: var(--state-color);
             border-radius: 4px;
             border: 2px solid var(--input-background);
         }

         #menuList li {
             padding: 10px 15px;
             border-bottom: 1px solid var(--secondary-border-color);
             transition: background-color var(--transition-speed); /* Smooth background transition */
         }
         #menuList li:last-child {
             border-bottom: none; /* Remove border from last item */
         }
         #menuList li:hover {
             background-color: var(--hover-background); /* Highlight on hover */
         }
         #menuList li a {
             text-decoration: none;
             color: var(--state-color);
             display: block; /* Make entire area clickable */
         }
         .item-index {
             display: inline-block;
             width: 35px; /* Slightly wider index */
             text-align: right;
             margin-right: 15px;
             font-weight: bold;
             opacity: 0.7;
             color: var(--text-color); /* Ensure index color */
         }
         /* Message style for empty list or errors within list */
         .message-text {
            text-align: center;
            padding: 20px;
            opacity: 0.7;
         }

        /* --- Static Content Block Styles (Example) --- */
        .content-container {
            position: relative;
            max-width: 600px;
            margin: 40px auto; /* Add more spacing */
            min-height: 100px;
        }
        .intro-text {
            padding: 20px;
            border-left: 4px solid var(--state-color);
            background: var(--paragraph-background);
            line-height: 1.7; /* Improve readability */
            transition: opacity var(--transition-speed);
            word-wrap: break-word;
            cursor: pointer; /* Indicate hover effect */
            opacity: 1;
            font-size: 0.95em;
        }
        .turing-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%; /* Cover the text area */
            object-fit: cover; /* Cover area, may crop */
            border: 2px solid var(--state-color);
            border-radius: 5px;
            box-shadow: 0 0 15px var(--image-shadow-color);
            opacity: 0; /* Hidden by default */
            transition: opacity var(--transition-speed);
            pointer-events: none; /* Non-interactive */
        }
        .content-container:hover .intro-text {
            opacity: 0; /* Hide text on hover */
        }
        .content-container:hover .turing-image {
            opacity: 1; /* Show image on hover */
        }

        /* --- Debug Panel --- */
        #debugPanelContainer {
            position: fixed;
            bottom: 10px;
            right: 10px;
            z-index: 999; /* Ensure it's above most content */
        }
        .debug-panel {
            background: rgba(0, 0, 0, 0.9); /* More opaque */
            padding: 15px;
            border: 1px solid var(--state-color);
            border-radius: 5px;
            font-size: 0.8em;
            max-width: 300px;
            display: none; /* Hidden by default */
            max-height: 50vh; /* Limit height */
            overflow-y: auto; /* Allow scrolling if content exceeds height */
        }
        .debug-panel.visible {
            display: block; /* Show when debug mode enabled */
        }
        .debug-panel strong {
             color: var(--state-color); /* Highlight labels */
        }

        /* --- Error Message --- */
        .error-message {
            color: var(--error-color);
            background-color: rgba(255, 68, 68, 0.1);
            border: 1px solid var(--error-color);
            text-align: center;
            padding: 15px;
            margin: 20px auto;
            max-width: 600px;
            border-radius: 5px;
        }

    </style>
</head>
<body>

    <div class="loading-bar"></div>

    <?php
        // Render the context tabs (dynamically generated by PHP)
        echo $navigator->renderTabs();
    ?>

    <div id="dynamicContentWrapper">
        <?php
            // Render the initial state of the Tape and List Menu on page load
            // Note: renderTape() and renderListMenu() return HTML strings now
            echo $navigator->renderTape();
            echo $navigator->renderListMenu();
        ?>
    </div>

    <br>
    <div class="content-container">
        <p class="intro-text">
			2617574563122404535962681114627755100903265670837020671250026036921181907025077617515652811709875574577060861909874614002201965902984305596084587922377712359229044316018387773094440784220447925263760181058307005024908100478250072372797046118999129824489631855694423620275131885972338001867511228830737977879052204056731142553344058424564361794623967797105001780149101538175611992667909173312603532359936539000153556587342067588235624614800982954206055418363169189426929681265133143950713892095378117226315834650657265926574917756397398485806974018808258500464087041031606667903476502207923919137921081015013170979282081686199346188341794734487945627870125131215675592772901762469827254991986477771619490517750373648478872507155966296922894803138965987670916021729525673039760478327393242841723600023451547737970761082039763530738898910192642818160479803060685791225275030350201045119802168947087518879810834099078677684864428364492829465596479214450767297244731829803666235514366830987087182292946826086179	

			::: ::: ::: ::: <:::> ::: ::: = {([(:::(:::) ::: :::)::: = (::: ::: ::: ::: ::: :::)] ::: [(:::(:::) ::: :::)::: ::: (::: ::: ::: ::: ::: ::: :::)]), (:::, :::, (::: ::: ::: ::: ::: :::) ::: (::: ::: ::: ::: ::: ::: :::))}
            <br><br><code>2 2 2 2 2 2 3 2 2 2 2 2 2 3 2 2 2 2 2 2 2 3 3 2</code><br><br>
			<code>using modular design patterns;</code>
        </p>
        <!--<img src="3.png" alt="Turing Machine Tape Visualization" class="turing-image"> </div>-->
		<img src="favicon.png" alt="Texed" class="turing-image"> </div>
    <div id="debugPanelContainer">
        <?php echo $navigator->renderDebugPanel(); // Render initial debug panel state ?>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Get Element References ---
            const loadingBar = document.querySelector('.loading-bar');
            const dynamicContentWrapper = document.getElementById('dynamicContentWrapper');
            const tabsContainer = document.querySelector('.context-tabs');
            const debugContainer = document.getElementById('debugPanelContainer');

            // --- Initial Loading Bar Animation ---
            if (loadingBar) {
                loadingBar.offsetWidth; // Force reflow/repaint
                loadingBar.style.transform = 'scaleX(1)'; // Animate to full width
                setTimeout(() => {
                    loadingBar.classList.add('hidden'); // Hide after animation
                }, 500); // Delay matches CSS transition duration
            }

            // --- Search Functionality (Using Event Delegation) ---
            if (dynamicContentWrapper) {
                // Listen for 'input' events within the dynamic wrapper
                dynamicContentWrapper.addEventListener('input', function(event) {
                    // Check if the event target is the menu search input
                    if (event.target.id === 'menuSearch') {
                        const searchInput = event.target;
                        const menuList = dynamicContentWrapper.querySelector('#menuList'); // Find list inside wrapper
                        if (!menuList) return; // Exit if list doesn't exist

                        const filter = searchInput.value.toLowerCase().trim();
                        const listItems = menuList.getElementsByTagName('li'); // Get CURRENT list items

                        // Iterate through items and toggle visibility based on filter
                        Array.from(listItems).forEach(function(item) {
                             // Special handling for "No items" or error messages within the list
                             if (item.classList.contains('message-text')) {
                                 item.style.display = ''; // Always show message items
                                 return;
                             }

                            const text = item.textContent || item.innerText; // Get item text
                            const isVisible = text.toLowerCase().includes(filter); // Check if text includes filter
                            item.style.display = isVisible ? '' : 'none'; // Show/hide item
                        });
                    }
                });
            }


            // --- AJAX Tab Switching Logic ---
            if (tabsContainer && dynamicContentWrapper) {
                tabsContainer.addEventListener('click', function(event) {
                    // Find the closest ancestor that is a tab item link
                    const clickedTab = event.target.closest('.tab-item');

                    // Exit if the click wasn't on a tab item or if the tab is already active
                    if (!clickedTab || clickedTab.classList.contains('active')) {
                        event.preventDefault(); // Still prevent default for safety
                        return;
                    }

                    event.preventDefault(); // IMPORTANT: Prevent the default link navigation

                    // Get context data from the clicked tab's data attributes
                    const contextId = clickedTab.dataset.contextId;
                    const targetParams = clickedTab.dataset.targetParams; // e.g., "context=2&head=0&user=false"
                    const targetUrl = window.location.pathname + '?' + targetParams; // Full target URL for History API

                    // --- Start AJAX Request ---
                    // 1. Visual Feedback: Indicate loading (fade out content, show loading bar)
                    dynamicContentWrapper.classList.add('loading');
                     if(loadingBar) {
                        loadingBar.classList.remove('hidden');
                        loadingBar.style.transform = 'scaleX(0)'; // Reset bar
                        loadingBar.offsetWidth; // Reflow
                        loadingBar.style.transform = 'scaleX(0.3)'; // Show partial progress quickly
                     }

                    // 2. Fetch new content from the server
                    // Construct the AJAX request URL (using current state + new context)
                    const ajaxUrl = `${window.location.pathname}?ajax=1&context=${contextId}&user=${encodeURIComponent('<?php echo $navigator->getCurrentState(); ?>')}&head=0`; // Reset head for context switch

                    fetch(ajaxUrl)
                        .then(response => {
                            // Check for network errors
                            if (!response.ok) {
                                throw new Error(`HTTP error! Status: ${response.status}`);
                            }
                            // Parse the JSON response
                            return response.json();
                        })
                        .then(data => {
                            // Check if the server-side operation was successful (based on our JSON structure)
                            if (data.success && data.menuHtml !== undefined && data.tapeHtml !== undefined) {
                                // --- Update Page Content ---
                                // Replace the inner HTML of the wrapper with the new content
                                dynamicContentWrapper.innerHTML = data.tapeHtml + data.menuHtml;

                                 // Update the Debug Panel's content if it exists
                                 if (debugContainer && data.debugHtml !== undefined) {
                                      debugContainer.innerHTML = data.debugHtml;
                                 }

                                // --- Update UI State ---
                                // Update the active state of the tabs
                                tabsContainer.querySelectorAll('.tab-item').forEach(tab => tab.classList.remove('active'));
                                clickedTab.classList.add('active');

                                // Update the browser's URL bar and history state without reloading
                                history.pushState({ contextId: contextId }, '', targetUrl);

                                 // --- Finish Visual Feedback ---
                                 // Remove loading class to trigger fade-in transition
                                 dynamicContentWrapper.classList.remove('loading');

                                 // Complete and hide the loading bar
                                 if(loadingBar) {
                                     loadingBar.style.transform = 'scaleX(1)';
                                     setTimeout(() => loadingBar.classList.add('hidden'), 300); // Hide after short delay
                                 }

                            } else {
                                // Handle cases where the server returned success:false or unexpected JSON structure
                                throw new Error(data.message || 'Invalid data received from server.');
                            }
                        })
                        .catch(error => {
                            // Handle network errors or errors thrown during processing
                            console.error('Error fetching context via AJAX:', error);
                            // Display an error message to the user within the content area
                            dynamicContentWrapper.innerHTML = `<div class="error-message">Failed to load content: ${error.message}. Please try again later.</div>`;
                             // Ensure loading states are removed on error
                             dynamicContentWrapper.classList.remove('loading');
                             if(loadingBar) loadingBar.classList.add('hidden');
                        });
                });
            }

            // --- Optional: Keyboard Navigation for Tape (Still Requires Page Reload) ---
            // This listener needs to be attached to the document as the tape itself might be replaced.
            // It checks if the tape exists *within* the dynamic wrapper.
            document.addEventListener('keydown', function(event) {
                // Find elements within the dynamic wrapper each time
                const currentSearchInput = dynamicContentWrapper?.querySelector('#menuSearch');
                const currentActiveCell = dynamicContentWrapper?.querySelector('.cell.active');

                 // Ignore keydown if focused on search input
                 if (document.activeElement === currentSearchInput) return;
                 // Ignore if tape/active cell doesn't exist in the current view
                 if (!currentActiveCell) return;


                const currentHead = parseInt(currentActiveCell.dataset.index);
                const currentTapeCells = dynamicContentWrapper.querySelectorAll('.tape .cell');
                const tapeSize = currentTapeCells.length;
                let newHead = currentHead;
                let shouldNavigate = false;

                // Determine new head position based on arrow keys
                switch(event.key) {
                    case 'ArrowLeft':
                        if (currentHead > 0) { newHead = currentHead - 1; shouldNavigate = true; }
                        break;
                    case 'ArrowRight':
                        if (tapeSize > 0 && currentHead < tapeSize - 1) { newHead = currentHead + 1; shouldNavigate = true; }
                        break;
                    default: return; // Exit if not a relevant arrow key
                }

                // If navigation should occur, construct URL and reload page
                if (shouldNavigate) {
                    event.preventDefault(); // Prevent default browser scroll behavior for arrow keys

                    // Get the current context ID (best source is the active tab's data attribute)
                    const activeTab = tabsContainer?.querySelector('.tab-item.active');
                    // Provide a fallback using PHP echo if active tab isn't found for some reason
                    const currentContextId = activeTab ? activeTab.dataset.contextId : '<?php echo $navigator->getCurrentContextId(); ?>';

                    // Build URL parameters for the new state (page reload)
                    const params = new URLSearchParams();
                    params.set('context', currentContextId);
                    params.set('user', '<?php echo $navigator->getCurrentState(); ?>'); // Preserve user state
                    params.set('head', newHead); // Set the new head position

                    // Trigger full page reload with the new state
                    window.location.href = window.location.pathname + '?' + params.toString();
                }
            });

        }); // End DOMContentLoaded
    </script>

</body>
</html>
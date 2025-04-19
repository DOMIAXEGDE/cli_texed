/*

Here’s a quick recipe to compile your new `web_cli.cpp` under MSYS2’s MINGW64 environment:

1. Launch the right shell  
   Open the “MSYS2 MinGW 64‑bit” shortcut (not the plain MSYS2 shell).

2. Update your package database & core system  
   bash
   pacman -Syu
   # (If it exits after updating, re-open the MINGW64 shell and run:)
   pacman -Su
   

3. Install the 64‑bit toolchain  
   bash
   pacman -S --needed mingw-w64-x86_64-toolchain
   
   This will pull in `g++`, `gcc`, `make`, etc.

4. Save your C++ source  
   Place your code in `web_cli.cpp` in your working directory.

5. Compile with C++17  
   bash
   g++ -std=c++17 -O2 -o web_cli.exe web_cli.cpp
   
   - If you see errors about `<filesystem>`, you may need to add `-lstdc++fs` at the end:
     bash
     g++ -std=c++17 -O2 -o web_cli.exe web_cli.cpp -lstdc++fs
     

6. Run it  
   bash
   ./web_cli.exe
   
   Or on Windows:
   bash
   ./web_cli.exe
   



Tip: if you want a one‑liner to both install the toolchain and compile, you can do:

bash
pacman -S --needed mingw-w64-x86_64-toolchain && \
g++ -std=c++17 -O2 -o web_cli.exe web_cli.cpp


You should now have a working `web_cli.exe` built under MSYS2 MINGW64.

*/

#include <iostream>
#include <fstream>
#include <sstream>
#include <vector>
#include <string>
#include <filesystem>
#include <regex>
#include <cstdlib>
#include <cstdio>
#include <algorithm>

namespace fs = std::filesystem;

const fs::path BASE_DIR = fs::current_path();
const fs::path CONFIG_FILE = BASE_DIR / "vortextz-terminal-config.txt";
const fs::path TMP_DIR = BASE_DIR / "tmp";

struct Command {
    std::string command_id;
    int initial_column, final_column;
    int initial_row, final_row;
    std::string input_file_base;
    std::string input_dir;
    std::string output_dir;
    std::string output_file_base;
};

// Cleanup function to remove blob_*.html files on exit
void cleanup_blobs() {
    if (!fs::exists(TMP_DIR)) return;
    for (auto& entry : fs::directory_iterator(TMP_DIR)) {
        auto fname = entry.path().filename().string();
        if (fname.rfind("blob_", 0) == 0 && entry.path().extension() == ".html") {
            std::error_code ec;
            fs::remove(entry.path(), ec);
        }
    }
}

// Read command sequences from config file
std::vector<std::string> read_command_sequence() {
    std::vector<std::string> seq;
    if (!fs::exists(CONFIG_FILE)) return seq;
    std::ifstream in(CONFIG_FILE);
    std::string line;
    while (std::getline(in, line)) {
        line.erase(line.begin(), std::find_if(line.begin(), line.end(), [](char c){ return !std::isspace(c); }));
        if (!line.empty() && line.front() == '<') {
            seq.push_back(line.substr(1));
        }
    }
    return seq;
}

// Parse a raw command line into Command struct
bool parse_command(const std::string& raw, Command& cmd) {
    std::string s = std::regex_replace(raw, std::regex("^[<>]+|[<>]+$"), "");
    std::vector<std::string> parts;
    std::stringstream ss(s);
    std::string item;
    while (std::getline(ss, item, '.')) parts.push_back(item);
    if (parts.size() != 7) return false;
    try {
        cmd.command_id = parts[0];
        auto split_range = [](const std::string& r, int& a, int& b) {
            size_t pos = r.find(',');
            if (pos == std::string::npos) {
                a = std::stoi(r);
                b = INT_MAX;
            } else {
                a = std::stoi(r.substr(0, pos));
                b = std::stoi(r.substr(pos + 1));
            }
        };
        split_range(parts[1], cmd.initial_column, cmd.final_column);
        split_range(parts[2], cmd.initial_row, cmd.final_row);
        cmd.input_file_base = parts[3];
        cmd.input_dir = parts[4];
        cmd.output_dir = parts[5];
        cmd.output_file_base = parts[6];
        return true;
    } catch (...) {
        return false;
    }
}

// Detect language by code heuristics (including inline CSS)
std::string detect_language(const std::string& code) {
    // If the code snippet contains style tags or inline CSS, treat as HTML
    if (code.find("<style") != std::string::npos || code.find("style=\"") != std::string::npos)
        return "html";

    std::string h = code;
    h.erase(h.begin(), std::find_if(h.begin(), h.end(), [](char c){ return !std::isspace(c); }));
    if (!h.empty() && h[0] == '<') return "html";
    static const std::vector<std::string> kws = {"function","var","let","const","import","export","class","document.","console.","(function"};
    for (auto& kw : kws) if (h.rfind(kw, 0) == 0) return "javascript";
    return "plaintext";
}

// Process code by slicing lines/columns
std::string process_code(const Command& cmd) {
    fs::path src = BASE_DIR / cmd.input_dir / (cmd.input_file_base + ".txt");
    if (!fs::exists(src)) return "";
    std::ifstream in(src);
    std::vector<std::string> lines;
    std::string line;
    while (std::getline(in, line)) lines.push_back(line);
    // skip numeric header?
    size_t start_idx = 0;
    if (!lines.empty() && std::all_of(lines[0].begin(), lines[0].end(), ::isdigit))
        start_idx = 1;
    std::vector<std::string> code_lines;
    for (size_t i = start_idx; i < lines.size(); ++i)
        code_lines.push_back(lines[i]);
    int r1 = std::max(1, cmd.initial_row) - 1;
    int r2 = std::min((int)code_lines.size(), cmd.final_row) - 1;
    std::ostringstream out;
    for (int i = r1; i <= r2; ++i) {
        const auto& ln = code_lines[i];
        int c1 = std::max(1, cmd.initial_column) - 1;
        int c2 = std::min((int)ln.size(), cmd.final_column);
        out << ln.substr(c1, c2 - c1) << "\n";
    }
    // write to output file if needed
    if (!cmd.output_dir.empty() && !cmd.output_file_base.empty()) {
        fs::path dst = BASE_DIR / cmd.output_dir / (cmd.output_file_base + ".txt");
        fs::create_directories(dst.parent_path());
        std::ofstream fout(dst);
        fout << out.str();
    }
    return out.str();
}

// Create HTML blob file and return path
fs::path create_blob(const std::string& code, int idx) {
    std::string lang = detect_language(code);
    // If HTML, output directly; else escape for <pre>
    std::string body;
    if (lang == "html") {
        body = code;
    } else {
        std::ostringstream esc;
        for (char c : code) {
            switch (c) {
                case '<': esc << "&lt;"; break;
                case '>': esc << "&gt;"; break;
                default: esc << c;
            }
        }
        body = "<pre>" + esc.str() + "</pre>";
    }

    // Wrap with basic HTML structure
    std::ostringstream html;
    html << "<!DOCTYPE html><html><head><meta charset=\"utf-8\">";
    html << "<title>Blob " << idx << "</title>";
    html << "</head><body>";
    html << body;
    html << "</body></html>";

    // Write to unique file
    char buf[32]; std::snprintf(buf, sizeof(buf), "blob_%02d_%08x.html", idx, std::rand());
    fs::path path = TMP_DIR / buf;
    fs::create_directories(TMP_DIR);
    std::ofstream out(path);
    out << html.str();
    return path;
}

// Rest of CLI and REPL loop unchanged...


// Handle a single CLI command
void handle_cli(const std::string& cmd_line) {
    std::vector<std::string> tokens;
    std::istringstream iss(cmd_line);
    for (std::string tok; iss >> tok; ) tokens.push_back(tok);
    if (tokens.empty() || tokens[0] == "help" || tokens[0] == "-h") {
        std::cout << "Commands: list, show <n>, open <n>\n";
        return;
    }
    auto raw = read_command_sequence();
    std::vector<std::pair<Command,std::string>> entries;
    for (auto& r : raw) {
        Command pc;
        if (!parse_command(r, pc)) continue;
        std::string code = process_code(pc);
        entries.emplace_back(pc, code);
    }
    if (tokens[0] == "list") {
        int i = 1;
        for (auto& [p, c] : entries) {
            std::cout << i++ << ". " << p.input_dir << "/" << p.input_file_base << ".txt\n";
        }
    }
    else if (tokens[0] == "show" || tokens[0] == "open") {
        if (tokens.size()<2) { std::cerr<<"Usage: "<<tokens[0]<<" <index>\n"; return; }
        int idx = std::stoi(tokens[1]) - 1;
        if (idx<0 || idx>= (int)entries.size()) { std::cerr<<"Index out of range\n"; return; }
        auto& [p, code] = entries[idx];
        if (tokens[0] == "show") {
            std::cout << code;
        } else {
            auto blob = create_blob(code, idx+1);
            std::string url = "file://" + blob.string();
            #ifdef _WIN32
                std::string cmd = "start " + url;
            #else
                std::string cmd = "xdg-open '" + url + "'";
            #endif
            std::system(cmd.c_str());
            std::cout << url << "\n";
        }
    } else {
        std::cerr << "Unknown command: " << tokens[0] << "\n";
    }
}

// Interactive REPL loop
void run_cli_loop() {
    std::string line;
    while (true) {
        std::cout << "vortextz> ";
        if (!std::getline(std::cin, line)) break;
        if (line.empty()) continue;
        std::string lower = line;
        std::transform(lower.begin(), lower.end(), lower.begin(), ::tolower);
        if (lower == "exit" || lower == "quit") break;
        handle_cli(line);
    }
    std::cout << "Goodbye.\n";
}

int main(int argc, char* argv[]) {
    std::srand((unsigned)std::time(nullptr));
    std::atexit(cleanup_blobs);
    bool server = false, gui = false, preload = false;
    std::vector<std::string> cmds;
    for (int i = 1; i < argc; ++i) {
        std::string a = argv[i];
        if (a == "--server") server = true;
        else if (a == "--gui") gui = true;
        else if (a == "--preload") preload = true;
        else cmds.push_back(a);
    }
    if (preload) {
        auto raw = read_command_sequence();
        int i = 1;
        for (auto& r : raw) {
            Command p; if (!parse_command(r, p)) continue;
            std::string code = process_code(p);
            auto blob = create_blob(code, i++);
            std::cout << "Blob " << i-1 << ": " << blob << "\n";
        }
        return 0;
    }
    if (server) {
        std::cerr << "HTTP server is not implemented in this C++ port.\n";
        return 1;
    }
    if (gui) {
        std::cerr << "GUI mode is not implemented in this C++ port.\n";
        return 1;
    }
    if (!cmds.empty()) {
        std::ostringstream oss;
        for (auto& t : cmds) oss << t << " ";
        handle_cli(oss.str());
    } else {
        run_cli_loop();
    }
    return 0;
}

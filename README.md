# vortextz‑terminal

A universal terminal‑style code‑slicing and preview tool, in three flavors:

- **PHP**: `vortextz‑terminal.php` (original implementation)
- **Python**: `web_cli.py` (with CLI, HTTP API & optional GUI)
- **C++**: `web_cli.cpp` (standalone REPL, blob cleanup)

---

## 📂 Directory Layout

```
project-root/
├── php/                         # PHP version
│   ├── vortextz‑terminal.php
│   └── vortextz-terminal-config.txt
│   └── tmp/                     # generated blobs
│       └── blob_*.html
│
├── python/                      # Python version
│   ├── web_cli.py
│   ├── vortextz-terminal-config.txt
│   ├── tmp/
│   └── requirements.txt         # Flask, etc. (optional)
│
└── cpp/                         # C++ version
    ├── web_cli.cpp
    ├── vortextz-terminal-config.txt
    └── tmp/
```

---

## ⚙️ Configuration File Format

Every implementation reads the same seven‑field, dot‑separated commands in `vortextz-terminal-config.txt`.  Each line must wrap **all** fields in a single `<…>` pair:

```
<command_id.initial_column,final_column.initial_row,final_row.input_base.input_dir.output_dir.output_base>
```

- **command_id**: arbitrary identifier
- **initial_column,final_column**: slice columns
- **initial_row,final_row**: slice rows
- **input_base**: input file basename (no extension)
- **input_dir**: subdirectory of source files
- **output_dir**: subdirectory for outputs (can be same)
- **output_base**: output file basename

Example:
```text
<1.0,30000.0,30000.learn.in.out.learn>
<2.0,30000.0,30000.ABEfermentation.in.out.ABEfermentation>
```


---

## 🐘 PHP Version

**Requirements**: PHP 7+, Apache or built-in server

### Install & Run

1. Copy `vortextz‑terminal.php` into your webroot (e.g. `htdocs/texed/`).
2. Ensure `vortextz-terminal-config.txt` is next to it, and a writable `tmp/` directory.
3. Access via browser:
   ```
   http://localhost/texed/vortextz‑terminal.php?cli="list"
   ```
4. Or use CLI wrapper:
   ```bash
   php vortextz‑terminal.php "list"
   ```

---

## 🐍 Python Version

**Requirements**: Python 3.7+, optional Flask & tkinter

### Setup

```bash
cd python/
python3 -m venv venv
source venv/bin/activate
pip install flask
```

### Usage

- **CLI**:
  ```bash
  python web_cli.py list
  ```
- **Interactive REPL**:
  ```bash
  python web_cli.py          # prompts with `vortextz>`
  ```
- **HTTP API** (with Flask):
  ```bash
  python web_cli.py --server
  # then GET /?cli="show 1"
  ```
- **GUI** (if tkinter installed):
  ```bash
  python web_cli.py --gui
  ```
- **Preload All Blobs**:
  ```bash
  python web_cli.py --preload
  ```

All blobs are written to `tmp/` and automatically deleted on exit via `atexit`.

---

## 🥷 C++ Version

**Requirements**: C++17 compiler; on Windows we recommend MSYS2 MINGW64

### Build on MSYS2 (MinGW‑64)

```bash
# In MSYS2 MinGW64 shell
pacman -Syu            # update
pacman -S mingw-w64-x86_64-toolchain
cd cpp/
# compile
g++ -std=c++17 -O2 -o web_cli.exe web_cli.cpp -lstdc++fs
```

### Build on Linux

```bash
g++ -std=c++17 -O2 -o web_cli web_cli.cpp
```

### Usage

- **REPL**:
  ```bash
  ./web_cli            # prompts with `vortextz>`
  ```
- **One‑off**:
  ```bash
  ./web_cli list
  ./web_cli show 2
  ```
- **Preload (generate all)**:
  ```bash
  ./web_cli --preload
  ```

Blobs appear under `tmp/` and are cleaned up on exit via `std::atexit`.

---

## 🔧 Common Commands

```bash
# list available commands
list

# show code slice #
show <n>

# open in default browser
open <n>

# exit interactive mode
exit | quit
```
## 📄 License

Licensed under MIT.


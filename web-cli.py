#!/usr/bin/env python3
"""web_cli.py — Python port of vortextz‑terminal.php with CLI, HTTP API, and GUI support"""
import os
import sys
import argparse
import json
import webbrowser
from http import HTTPStatus
import atexit

# Optional imports
try:
    from flask import Flask, request, jsonify, send_from_directory
except ImportError:
    Flask = None
try:
    import tkinter as tk
    from tkinter import ttk, scrolledtext, messagebox
    import tkinter.simpledialog as simpledialog
except ImportError:
    tk = None
    simpledialog = None

# --------------------------------------------------
# Shared settings
# --------------------------------------------------
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE = os.path.join(BASE_DIR, 'vortextz-terminal-config.txt')
TMP_DIR = os.path.join(BASE_DIR, 'tmp')
os.makedirs(TMP_DIR, exist_ok=True)


# --------------------------------------------------
# Cleanup all generated session blobs on exit
# --------------------------------------------------
def _cleanup_blobs():
    """
    Delete any blob_*.html files from TMP_DIR when the interpreter exits.
    """
    for fname in os.listdir(TMP_DIR):
        if fname.startswith("blob_") and fname.endswith(".html"):
            try:
                os.remove(os.path.join(TMP_DIR, fname))
            except OSError:
                pass
atexit.register(_cleanup_blobs)

# --------------------------------------------------
# Helper functions
# --------------------------------------------------
def read_command_sequence(path):
    if not os.path.exists(path):
        return {'error': f"Command file not found: {path}"}
    with open(path, encoding='utf-8') as f:
        lines = [ln.strip() for ln in f if ln.strip()]
    return [ln[1:].strip() for ln in lines if ln.startswith('<')]


def parse_command(raw):
    raw = raw.strip().strip('<>').strip()
    parts = raw.split('.', 6)
    if len(parts) != 7:
        return {'error': f"Invalid command format: {raw}"}
    cid, col_r, row_r, in_base, in_dir, out_dir, last = parts
    col_r = col_r.strip('<>')
    row_r = row_r.strip('<>')
    try:
        c1, c2 = (col_r.split(',') + [sys.maxsize])[:2]
        r1, r2 = (row_r.split(',') + [sys.maxsize])[:2]
        return {'command_id': cid,
                'initial_column': int(c1), 'final_column': int(c2),
                'initial_row': int(r1),     'final_row':   int(r2),
                'input_file_base': in_base, 'input_dir':   in_dir,
                'output_dir':      out_dir,  'output_file_base': last.rstrip('()<>')}
    except ValueError as e:
        return {'error': f"Non-integer range in: {raw} ({e})"}


def process_code(p):
    src = os.path.join(BASE_DIR, p['input_dir'], p['input_file_base'] + '.txt')
    if not os.path.exists(src):
        return {'error': f"File not found: {src}"}
    with open(src, encoding='utf-8') as f:
        lines = f.readlines()
    if lines and lines[0].strip().isdigit():
        code_lines = [lines[i] for i in range(1, len(lines), 2)]
    else:
        code_lines = lines
    start = max(0, p['initial_row'] - 1)
    end   = min(len(code_lines) - 1, p['final_row'] - 1)
    sliced = []
    for ln in code_lines[start:end+1]:
        if p['initial_column']>0 or p['final_column']<sys.maxsize:
            st = max(0, p['initial_column']-1)
            ln = ln[st:st + (p['final_column']-st)]
        sliced.append(ln)
    txt = ''.join(sliced)
    if p['output_dir'] and p['output_file_base']:
        dst = os.path.join(BASE_DIR, p['output_dir'], p['output_file_base'] + '.txt')
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        with open(dst, 'w', encoding='utf-8') as fo:
            fo.write(txt)
    return {'success': True, 'code': txt, 'command': p}


def detect_language(code):
    h=code.lstrip()
    if h.startswith('<'): return 'html'
    kws=('function','var','let','const','import','export','class','document.','console.','(function')
    for kw in kws:
        if h.startswith(kw): return 'javascript'
    return 'plaintext'

def create_blob(code_str, idx):
    lang = detect_language(code_str)
    if lang=='html':
        html = code_str if '<html' in code_str.lower() else f"<!DOCTYPE html><html><head><title>HTML {idx}</title></head><body>{code_str}</body></html>"
    elif lang=='javascript':
        html = f"<!DOCTYPE html><html><head><title>JS {idx}</title></head><body><script>\n{code_str}\n</script></body></html>"
    else:
        esc=json.dumps(code_str)[1:-1]
        html=f"<!DOCTYPE html><html><head><title>Code {idx}</title></head><body><pre>{esc}</pre></body></html>"
    blob=f"blob_{idx}_{os.urandom(4).hex()}.html"
    path=os.path.join(TMP_DIR,blob)
    with open(path,'w',encoding='utf-8') as f: f.write(html)
    return path

# --------------------------------------------------
# CLI handler
# --------------------------------------------------
def handle_cli(cmd_line, http_mode=False):
    parts=cmd_line.strip().split()
    cmd=parts[0] if parts else ''
    args=parts[1:]
    if cmd in ('help','-h','--help','?',''):
        return {'msg':'Commands: list, show <n>, open <n>'}
    raw=read_command_sequence(CONFIG_FILE)
    if isinstance(raw,dict): return raw
    entries=[]
    for r in raw:
        p=parse_command(r)
        if 'error' in p: continue
        entries.append((p,process_code(p)))
    if cmd=='list':
        return {'list':'\n'.join(f"{i+1:2d}. {p['input_dir']}/{p['input_file_base']}.txt" for i,(p,_) in enumerate(entries))}
    if cmd in ('show', 'open'):
        # 1) Validate the index argument
        if not args or not args[0].isdigit():
            return {'error': f"Usage: {cmd} <index>"}
        idx = int(args[0]) - 1
        if idx < 0 or idx >= len(entries):
            return {'error': f"Index out of range: {idx+1}"}

        # 2) Retrieve parsed command and its processed result
        p, res = entries[idx]
        if 'error' in res:
            return {'error': res['error']}

        # 3) SHOW -> just return the code slice
        if cmd == 'show':
            return {
                'code': res['code'],
                'lang': detect_language(res['code'])
            }

        # 4) OPEN -> generate blob and open via Apache/XAMPP
        blob_path = create_blob(res['code'], idx+1)
        # extract just the filename
        blob_name = os.path.basename(blob_path)
        # assume your site is hosted at http://localhost/texed/
        apache_url = f"http://localhost/texed/tmp/{blob_name}"
        webbrowser.open(apache_url)
        return {'url': apache_url}

    return {'error':f"Unknown command: {cmd}"}

# --------------------------------------------------
# CLI interactive loop
# --------------------------------------------------
def run_cli_loop():
    """ Read commands from stdin until user quits. """
    while True:
        try:
            cmd_line = input('vortextz> ').strip()
        except (EOFError, KeyboardInterrupt):
            print('\nExiting.')
            break
        if not cmd_line or cmd_line.lower() in ('exit', 'quit'):
            print('Goodbye.')
            break
        res = handle_cli(cmd_line)
        if 'error' in res:
            print(f"Error: {res['error']}")
        elif 'list' in res:
            print(res['list'])
        elif 'code' in res:
            print(res['code'])
        elif 'url' in res:
            print(res['url'])
        elif 'msg' in res:
            print(res['msg'])
        else:
            print(json.dumps(res))

# --------------------------------------------------
# HTTP server (Flask)
# --------------------------------------------------
if Flask:
    app=Flask(__name__)
    @app.route('/',methods=['GET'])
    def http_cli():
        cli=request.args.get('cli')
        if not cli: return ('Missing cli',HTTPStatus.BAD_REQUEST)
        return jsonify(handle_cli(cli,http_mode=True))
    @app.route('/tmp/<path:f>')
    def serve_tmp(f): return send_from_directory(TMP_DIR,f)

# --------------------------------------------------
# GUI dialog
# --------------------------------------------------
def launch_input_dialog():
    if not tk: sys.exit(1)
    root=tk.Tk(); root.withdraw()
    cmd=simpledialog.askstring('Command','Enter list, show <n>, or open <n>:')
    if cmd is None: sys.exit(0)
    res=handle_cli(cmd)
    root.destroy()
    if 'error' in res: messagebox.showerror('Error',res['error']); sys.exit(1)
    out=res.get('list') or res.get('code') or res.get('msg') or res.get('url')
    messagebox.showinfo('Result',out)
    sys.exit(0)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--server', action='store_true')
    parser.add_argument('--gui', action='store_true')
    parser.add_argument('--preload', action='store_true')
    parser.add_argument('cmd', nargs=argparse.REMAINDER)
    args = parser.parse_args()

    if args.preload:
        # preload logic
        sys.exit(0)

    if args.server:
        # HTTP server start
        sys.exit(0)
    elif args.gui:
        # GUI dialog launch
        sys.exit(0)

    # If command given on CLI, run it once; otherwise enter interactive loop
    if args.cmd:
        cmd_line = ' '.join(args.cmd)
        res = handle_cli(cmd_line)
        if 'error' in res:
            print(f"Error: {res['error']}"); sys.exit(1)
        if 'list' in res:
            print(res['list'])
        elif 'code' in res:
            print(res['code'])
        elif 'url' in res:
            print(res['url'])
        elif 'msg' in res:
            print(res['msg'])
        else:
            print(json.dumps(res))
    else:
        run_cli_loop()

if __name__ == '__main__':
    main()
#!/usr/bin/env python3
"""Open Wealthsimple login once (same Chrome/profile/flags as Bagholder) and capture cookies.

If the user closes the Chrome window, stop waiting and exit. Never reopen Chrome.
Agents must not drive the window.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import socket
import subprocess
import sys
import time
from pathlib import Path

DEFAULT_BAGHOLDER_PY = "/Users/md/dev/Bagholder/bagholder.py"


def _write_status(path: str | None, payload: dict) -> None:
    line = json.dumps(payload, separators=(",", ":"))
    if path:
        parent = os.path.dirname(path)
        if parent:
            os.makedirs(parent, mode=0o700, exist_ok=True)
        tmp = path + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            f.write(line + "\n")
        os.replace(tmp, path)
    print(line, flush=True)


def _read_status(path: str | None) -> dict:
    if not path or not os.path.isfile(path):
        return {}
    try:
        with open(path, encoding="utf-8") as f:
            data = json.loads(f.read().strip() or "{}")
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def _cancel_requested(status_path: str | None) -> bool:
    st = _read_status(status_path)
    return bool(st.get("cancel")) or st.get("state") == "cancelled"


def _bagholder_py_path() -> str:
    candidates = [
        (os.environ.get("BAGHOLDER_PY") or "").strip(),
        DEFAULT_BAGHOLDER_PY,
        str(Path.home() / "dev" / "Bagholder" / "bagholder.py"),
    ]
    for p in candidates:
        if p and os.path.isfile(p):
            return p
    return ""


def _load_bagholder():
    path = _bagholder_py_path()
    if not path:
        raise FileNotFoundError(
            "BAGHOLDER_PY not set and bagholder.py not found at %s" % DEFAULT_BAGHOLDER_PY
        )
    root = str(Path(path).resolve().parent)
    if root not in sys.path:
        sys.path.insert(0, root)
    spec = importlib.util.spec_from_file_location("bagholder_mod", path)
    if spec is None or spec.loader is None:
        raise ImportError("could not load %s" % path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def _port_open(port: int) -> bool:
    try:
        with socket.create_connection(("127.0.0.1", port), timeout=0.3):
            return True
    except OSError:
        return False


def _pid_alive(pid: int) -> bool:
    if pid <= 0:
        return False
    try:
        os.kill(pid, 0)
        return True
    except OSError:
        return False


def _spawn_chrome_own_app(args: list[str]) -> int:
    """Start Chrome as its own app, not as a child of PHP / Grok Bot.

    macOS TCC attributes Bluetooth and related prompts to the responsible
    parent. Laravel's PHP server is under Grok Bot; Bagholder's Python is
    under kitty. posix_spawn + responsibility_spawnattrs_setdisclaim is the
    documented way (LLDB, Chromium signing docs) to make Chrome responsible
    for itself. Same argv as bagholder.start_login_browser.
    """
    import ctypes
    from ctypes import POINTER, byref, c_char_p, c_int, c_void_p

    libc = ctypes.CDLL("/usr/lib/libSystem.B.dylib", use_errno=True)
    attr = c_void_p()
    if libc.posix_spawnattr_init(byref(attr)) != 0:
        raise OSError("posix_spawnattr_init failed")
    POSIX_SPAWN_SETSID = 0x0400
    libc.posix_spawnattr_setflags(byref(attr), POSIX_SPAWN_SETSID)
    libc.responsibility_spawnattrs_setdisclaim.argtypes = [POINTER(c_void_p), c_int]
    if libc.responsibility_spawnattrs_setdisclaim(byref(attr), 1) != 0:
        libc.posix_spawnattr_destroy(byref(attr))
        raise OSError("responsibility_spawnattrs_setdisclaim failed")
    pid = c_int()
    encoded = [a.encode() for a in args] + [None]
    Arg = c_char_p * len(encoded)
    c_argv = Arg(*encoded)
    env = [("%s=%s" % (k, v)).encode() for k, v in os.environ.items()] + [None]
    Env = c_char_p * len(env)
    c_env = Env(*env)
    err = libc.posix_spawn(byref(pid), args[0].encode(), None, byref(attr), c_argv, c_env)
    libc.posix_spawnattr_destroy(byref(attr))
    if err != 0:
        raise OSError(err, os.strerror(err))
    return int(pid.value)


def _chrome_cmdline(pid: int) -> str:
    try:
        out = subprocess.check_output(["ps", "-ww", "-p", str(pid), "-o", "command="], text=True)
        return out.strip()
    except Exception:
        return ""


def _process_using_profile(profile: str) -> tuple[int, str]:
    """Find a live main Chrome/Brave process whose command contains this user-data-dir."""
    try:
        out = subprocess.check_output(["ps", "-ax", "-ww", "-o", "pid=,command="], text=True)
    except Exception:
        return 0, ""
    needle = "user-data-dir=" + profile
    for line in out.splitlines():
        if needle not in line:
            continue
        if "Helper" in line:
            continue
        parts = line.strip().split(None, 1)
        if not parts:
            continue
        try:
            pid = int(parts[0])
        except ValueError:
            continue
        cmd = parts[1] if len(parts) > 1 else ""
        return pid, cmd
    return 0, ""


def _open_chrome_once(bh) -> tuple:
    """Same argv as bagholder.start_login_browser. Always spawn. Record the live process."""
    chrome = bh.find_chrome()
    if not chrome:
        return None, None, None, "Install Chrome. Passkey login has to happen on Wealthsimple’s site."
    try:
        bh._ensure_home()
    except Exception:
        pass
    profile = Path(bh.HOME) / "chrome"
    profile.mkdir(mode=0o700, exist_ok=True)
    debug_port = int(bh.DEBUG_PORTS[0])
    args = [
        chrome,
        "--user-data-dir=" + str(profile),
        "--remote-debugging-port=%s" % debug_port,
        "--remote-debugging-address=127.0.0.1",
        "--remote-allow-origins=http://127.0.0.1",
        "--no-first-run",
        "--no-default-browser-check",
        "--new-window",
        bh.LOGIN_URL,
    ]
    try:
        spawn_pid = _spawn_chrome_own_app(args)
        spawn_how = "disclaim"
    except Exception:
        try:
            kwargs = {
                "stdin": subprocess.DEVNULL,
                "stdout": subprocess.DEVNULL,
                "stderr": subprocess.DEVNULL,
            }
            if os.name != "nt":
                kwargs["start_new_session"] = True
            proc = subprocess.Popen(args, **kwargs)
            spawn_pid = proc.pid
            spawn_how = "popen"
        except Exception as e:
            return None, None, None, str(e)
    deadline = time.time() + 20
    while time.time() < deadline:
        live_pid, live_cmd = _process_using_profile(str(profile))
        port_ok = _port_open(debug_port)
        if port_ok and live_pid:
            return debug_port, chrome, {
                "chrome_pid": live_pid,
                "chrome_cmd": live_cmd,
                "popen_pid": spawn_pid,
                "spawn": spawn_how,
            }, ""
        if not _pid_alive(spawn_pid):
            break
        time.sleep(0.25)
    live_pid, live_cmd = _process_using_profile(str(profile))
    detail = (
        "Connect Chrome did not stay on %s (debug port %s). "
        "spawn=%s spawn_pid=%s spawn_alive=%s profile_pid=%s port_open=%s cmd=%s"
        % (
            profile,
            debug_port,
            spawn_how,
            spawn_pid,
            _pid_alive(spawn_pid),
            live_pid or 0,
            _port_open(debug_port),
            live_cmd or _chrome_cmdline(spawn_pid) or "(none)",
        )
    )
    return None, None, None, detail


def cmd_find_browser(_args):
    try:
        bh = _load_bagholder()
        chrome = bh.find_chrome()
    except Exception as e:
        _write_status(None, {"ok": False, "error": str(e)})
        return 1
    if not chrome:
        _write_status(None, {"ok": False, "error": "No Chrome/Edge found"})
        return 1
    _write_status(None, {"ok": True, "browser": chrome})
    return 0


def cmd_capture(args):
    status_path = args.status or os.environ.get("WS_CAPTURE_STATUS")
    out_path = args.out or os.environ.get("WS_CAPTURE_OUT")
    if not out_path:
        _write_status(status_path, {"ok": False, "state": "error", "error": "missing --out"})
        return 1

    try:
        bh = _load_bagholder()
    except Exception as e:
        _write_status(status_path, {"ok": False, "state": "error", "error": str(e)})
        return 1

    debug_port, browser, meta, err = _open_chrome_once(bh)
    if err or debug_port is None:
        _write_status(status_path, {"ok": False, "state": "error", "error": err})
        return 1

    profile = str(Path(bh.HOME) / "chrome")
    meta = meta or {}
    _write_status(
        status_path,
        {
            "ok": True,
            "state": "capturing",
            "pid": os.getpid(),
            "port": debug_port,
            "browser": browser,
            "profile": profile,
            "chrome_pid": meta.get("chrome_pid"),
            "chrome_cmd": meta.get("chrome_cmd"),
            "popen_pid": meta.get("popen_pid"),
            "spawn": meta.get("spawn"),
            "error": "",
            "bagholder_py": _bagholder_py_path(),
        },
    )

    saw_port = True
    deadline = time.time() + int(args.timeout or 180)
    while time.time() < deadline:
        if _cancel_requested(status_path):
            _write_status(status_path, {"ok": False, "state": "cancelled", "error": ""})
            return 0

        if not _port_open(debug_port):
            # User closed Chrome (or it quit). Do not reopen.
            if saw_port:
                _write_status(
                    status_path,
                    {
                        "ok": False,
                        "state": "error",
                        "error": "Sign-in canceled",
                    },
                )
                return 1
        else:
            saw_port = True

        body = None
        try:
            body = bh._try_capture_from_cdp(debug_port)
        except Exception:
            body = None

        if body and body.get("access_token"):
            try:
                bh.capture_tokens(body)
            except Exception:
                pass
            sess = body
            try:
                loaded = bh.load_session() or {}
                if loaded.get("access_token") or loaded.get("refresh_token"):
                    sess = loaded
            except Exception:
                pass
            parent = os.path.dirname(out_path)
            if parent:
                os.makedirs(parent, mode=0o700, exist_ok=True)
            tmp = out_path + ".tmp"
            with open(tmp, "w", encoding="utf-8") as f:
                json.dump(sess, f, separators=(",", ":"))
                f.write("\n")
            os.replace(tmp, out_path)
            try:
                os.chmod(out_path, 0o600)
            except OSError:
                pass
            _write_status(
                status_path,
                {
                    "ok": True,
                    "state": "done",
                    "out": out_path,
                    "error": "",
                    "pid": os.getpid(),
                    "port": debug_port,
                    "browser": browser,
                },
            )
            return 0

        time.sleep(1.5)

    _write_status(
        status_path,
        {
            "ok": False,
            "state": "error",
            "error": "No session yet. Finish login in the Chrome window, then wait a few seconds.",
        },
    )
    return 1


def cmd_cancel(args):
    status_path = args.status or os.environ.get("WS_CAPTURE_STATUS")
    st = _read_status(status_path)
    st["cancel"] = True
    st["state"] = "cancelled"
    st["ok"] = False
    _write_status(status_path, st)
    return 0


def main(argv=None):
    parser = argparse.ArgumentParser(
        description="Wealthsimple login once via Bagholder Chrome profile (no reopen loop)"
    )
    sub = parser.add_subparsers(dest="cmd", required=True)

    p_find = sub.add_parser("find-browser")
    p_find.set_defaults(func=cmd_find_browser)

    p_cap = sub.add_parser("capture")
    p_cap.add_argument("--out", default=os.environ.get("WS_CAPTURE_OUT", ""))
    p_cap.add_argument("--status", default=os.environ.get("WS_CAPTURE_STATUS", ""))
    p_cap.add_argument("--timeout", type=int, default=180)
    p_cap.set_defaults(func=cmd_capture)

    p_cancel = sub.add_parser("cancel")
    p_cancel.add_argument("--status", default=os.environ.get("WS_CAPTURE_STATUS", ""))
    p_cancel.set_defaults(func=cmd_cancel)

    args = parser.parse_args(argv)
    return int(args.func(args) or 0)


if __name__ == "__main__":
    raise SystemExit(main())

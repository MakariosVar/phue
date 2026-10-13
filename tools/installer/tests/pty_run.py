#!/usr/bin/env python3
"""Drive a command inside a pty and report what the terminal actually saw.

The installer's screens are only testable on a terminal: it refuses to run
interactive without one, and a pipe cannot answer ESC[6n or carry arrow keys.
This gives the test suite a terminal to run it on, and answers the question a
screenshot cannot — was the screen drawn once, or once per keystroke?

Keys are sent when a marker appears in the output rather than after a fixed
delay, because the installer scans the framework's modules before it draws its
first frame and a timer would be measuring PHP startup rather than the menu.
"""

import os
import pty
import re
import select
import signal
import sys
import time

ANSI = re.compile(r"\x1b\[[0-9;?]*[A-Za-z]|\x1b\][^\x07]*\x07|\x1b[=>]|\r")
CURSOR_REPORT = re.compile(r"\x1b\[6n")
DEBUG = bool(os.environ.get("PTY_DEBUG"))


class Screen:
    """Just enough terminal to answer "what does the user see?".

    A full-screen menu writes the whole frame again on every keystroke, so the
    byte stream contains one copy per key and counting them proves nothing. What
    the user sees is the frame after cursor addressing and erases are applied,
    which is what this replays: CUP and CUD/CUU/CUF/CUB, ED and EL, CR, LF, and
    nothing else. Anything the installer emits that is not understood here is a
    reason to distrust the harness rather than to ignore it.
    """

    CSI = re.compile(r"\x1b\[([0-9;?]*)([A-Za-z])")

    def __init__(self, rows=200, columns=200):
        self.rows, self.columns = rows, columns
        self.buffer = [[" "] * columns for _ in range(rows)]
        self.row = self.col = 0

    def _clamp(self):
        self.row = max(0, min(self.row, self.rows - 1))
        self.col = max(0, min(self.col, self.columns - 1))

    def _erase_down(self):
        for row in range(self.row, self.rows):
            start = self.col if row == self.row else 0
            self.buffer[row][start:] = [" "] * (self.columns - start)

    def feed(self, text):
        index = 0

        while index < len(text):
            char = text[index]

            if char == "\x1b":
                match = self.CSI.match(text, index)
                if match:
                    self._csi(match.group(1), match.group(2))
                    index = match.end()
                    continue
                index += 2 if text[index:index + 2] in ("\x1b]", "\x1b=") else 1
                continue

            if char == "\n":
                self.row += 1
                self.col = 0
            elif char == "\r":
                self.col = 0
            elif char == "\x07":
                pass
            else:
                self.buffer[self.row][self.col] = char
                self.col += 1
                if self.col >= self.columns:
                    self.col = 0
                    self.row += 1

            self._clamp()
            index += 1

        return self

    def _csi(self, params, final):
        values = [int(p) for p in params.split(";") if p.isdigit()]

        def value(position, fallback=1):
            return values[position] if len(values) > position else fallback

        if final == "H" or final == "f":
            self.row, self.col = value(0) - 1, value(1) - 1
        elif final == "A":
            self.row -= value(0)
        elif final == "B":
            self.row += value(0)
        elif final == "C":
            self.col += value(0)
        elif final == "D":
            self.col -= value(0)
        elif final == "G":
            self.col = value(0) - 1
        elif final == "J":
            mode = value(0, 0)
            if mode == 0:
                self._erase_down()
            elif mode == 2:
                self.buffer = [[" "] * self.columns for _ in range(self.rows)]
        elif final == "K":
            self.buffer[self.row][self.col:] = [" "] * (self.columns - self.col)

        self._clamp()

    def visible(self):
        """The screen as a user reads it: full lines, trailing blanks removed."""
        lines = ["".join(row).rstrip() for row in self.buffer]
        while lines and not lines[-1]:
            lines.pop()
        return "\n".join(lines)


ROWS = 40
COLUMNS = 100


def run(argv, keys=(), marker=None, settle=0.4, timeout=25.0, rows=ROWS, columns=COLUMNS):
    """Run argv on a pty, send keys, and return the raw bytes and the screen.

    Keys are sent when the output goes quiet rather than on a timer. The menu
    redraws before it waits for the next key, so "quiet for a moment" means the
    program has finished reacting to the previous one — the only signal that
    says another key will not be dropped mid-sequence.

    The geometry defaults to a terminal a person actually has. It used to be
    200x200, which was large enough that every menu fitted and the harness could
    not tell the difference between a menu that fits and one that has been
    truncated to fit. The cost was silent: a test counting menu rows to build a
    key sequence counted the rows that survived, not the rows that exist, and
    pressed a number of downs that stopped short of the row it meant to reach.
    """
    pid, fd = pty.fork()
    if pid == 0:
        os.environ["TERM"] = "xterm-256color"
        os.environ["COLUMNS"] = str(columns)
        os.environ["LINES"] = str(rows)
        os.execvp(argv[0], argv)

    out = bytearray()
    pending = list(keys)
    deadline = time.time() + timeout
    armed = marker is None
    screen = Screen(rows=rows, columns=columns)
    last_output = time.time()
    next_send = time.time() + settle

    while time.time() < deadline:
        ready, _, _ = select.select([fd], [], [], 0.02)

        if ready:
            try:
                chunk = os.read(fd, 65536)
            except OSError:
                break
            if not chunk:
                break
            out += chunk
            last_output = time.time()

            text = chunk.decode("utf8", "replace")
            screen.feed(text)

            # A pty has no terminal on the far end, so nothing answers a cursor
            # position report unless this does. Without the reply the installer
            # falls back to counting lines back up, which is the one code path a
            # real user never takes — the tests would be measuring a fallback
            # instead of the program.
            if CURSOR_REPORT.search(text):
                try:
                    os.write(fd, f"\x1b[{screen.row + 1};{screen.col + 1}R".encode())
                except OSError:
                    break

            if not armed and marker in out:
                armed = True
                next_send = time.time() + settle

        if armed and pending and time.time() >= next_send \
                and time.time() - last_output >= settle:
            try:
                os.write(fd, pending.pop(0))
            except OSError:
                break
            if DEBUG:
                print("sent %d keys, %d left" % (len(keys) - len(pending), len(pending)),
                      file=sys.stderr)
            next_send = time.time() + settle
            last_output = time.time()
        elif armed and not pending and time.time() - last_output >= settle:
            break

    try:
        os.kill(pid, signal.SIGKILL)
    except OSError:
        pass
    try:
        os.waitpid(pid, 0)
    except OSError:
        pass
    os.close(fd)

    return bytes(out), screen


def parse_keys(spec):
    """Turn "--keys '\\e[B,\\r'" into the bytes a terminal would send.

    \\e is expanded to ESC before decoding, and never handed to unicode_escape:
    that codec does not define \\e, so it passes the two characters through
    unchanged and the program under test receives a backslash and an "e" where
    it expected one key. Python 3.12 warns about exactly this.
    """
    keys = []

    for token in spec.split(","):
        if not token:
            continue
        keys.append(token.replace("\\e", "\\x1b").encode("utf8").decode("unicode_escape").encode("latin1"))

    return keys


def main():
    argv = sys.argv[1:]
    keys, marker, as_screen, as_raw = [], None, False, False

    while argv and argv[0].startswith("--"):
        option = argv.pop(0)
        if option == "--keys":
            keys = parse_keys(argv.pop(0))
        elif option == "--marker":
            marker = argv.pop(0).encode()
        elif option == "--screen":
            as_screen = True
        elif option == "--raw":
            as_raw = True

    raw, screen = run(argv, keys, marker)

    if as_raw:
        sys.stdout.write(repr(raw.decode("utf8", "replace")))
    elif as_screen:
        sys.stdout.write(screen.visible() + "\n")
    else:
        sys.stdout.write(ANSI.sub("", raw.decode("utf8", "replace")))


if __name__ == "__main__":
    main()

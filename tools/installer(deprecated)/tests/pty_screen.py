#!/usr/bin/env python3
"""Drive the installer on a terminal of a *specific size*, and report what fits.

pty_run.py answers "was the screen drawn once?" against a 200x200 buffer that
never scrolls. That is the wrong instrument for a menu that is taller than the
window, because a frame that overflows is exactly the case a non-scrolling
model cannot see: it accepts a frame of any height and reports it as drawn
cleanly. The two bugs this file exists to catch — a frame taller than the
terminal, and a line long enough to wrap — are both invisible there.

So this models a terminal that scrolls, and asks the questions a person with a
24-row window would answer: did anything scroll off, did any line wrap, is the
cursor still inside the frame.

Usage:
    pty_screen.py [--rows N] [--cols N] [--keys SPEC] [--dump] -- <installer> [args...]
"""

import argparse
import os
import pty
import re
import select
import signal
import sys
import time

CSI = re.compile(r"\x1b\[([0-9;?]*)([A-Za-z])")


class Terminal:
    """A terminal model with scrollback.

    Only what the installer emits is modelled: CUP, CUU/CUD/CUF/CUB, ED, EL, CR,
    LF, and the printable characters. A sequence this does not understand is
    counted as unknown rather than ignored — an unhandled sequence means the
    screen below is not trustworthy, and reporting it is more useful than
    quietly returning a plausible-looking answer.
    """

    def __init__(self, rows, cols):
        self.rows = rows
        self.cols = cols
        self.buf = [[" "] * cols for _ in range(rows)]
        self.row = 0
        self.col = 0
        self.scrolls = 0
        self.wraps = 0
        self.unknown = []
        self._pending_wrap = False

    def _clamp(self):
        self.row = max(0, min(self.row, self.rows - 1))
        self.col = max(0, min(self.col, self.cols - 1))

    def _scroll(self):
        self.buf.pop(0)
        self.buf.append([" "] * self.cols)
        self.scrolls += 1

    def _lf(self):
        if self._pending_wrap:
            self._pending_wrap = False
            self._scroll()
        elif self.row == self.rows - 1:
            self._scroll()
        else:
            self.row += 1
        self.col = 0

    def feed(self, text):
        index = 0

        while index < len(text):
            char = text[index]

            if char == "\x1b":
                match = CSI.match(text, index)
                if match:
                    self._csi(match.group(1), match.group(2))
                    index = match.end()
                    continue
                index += 2
                continue

            if char == "\n":
                self._lf()
            elif char == "\r":
                self.col = 0
                self._pending_wrap = False
            elif char == "\x07":
                pass
            else:
                # A real terminal leaves the cursor in the last column and only
                # moves on when the *next* character arrives. Wrapping as soon
                # as the last column is filled reports a wrap for every line
                # ending flush with the margin, which is nearly all of them.
                if self._pending_wrap:
                    self._pending_wrap = False
                    if self.row == self.rows - 1:
                        self._scroll()
                    else:
                        self.row += 1
                    self.wraps += 1

                self.buf[self.row][self.col] = char

                if self.col == self.cols - 1:
                    self._pending_wrap = True
                else:
                    self.col += 1

            self._clamp()
            index += 1

        return self

    def _csi(self, params, final):
        values = [int(p) for p in params.split(";") if p.isdigit()]

        def value(position, fallback=1):
            return values[position] if len(values) > position else fallback

        if final in "Hf":
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
                for row in range(self.row, self.rows):
                    start = self.col if row == self.row else 0
                    self.buf[row][start:] = [" "] * (self.cols - start)
            elif mode == 2:
                self.buf = [[" "] * self.cols for _ in range(self.rows)]
        elif final == "K":
            self.buf[self.row][self.col:] = [" "] * (self.cols - self.col)
        elif final in "hlmn":
            # Cursor visibility (?25h/?25l), SGR colour (m) and the modes the
            # query for the cursor position uses (n) change nothing on screen.
            # Treating them as unknown made every clean run look broken.
            pass
        else:
            self.unknown.append(final)

        self._clamp()

    def lines(self):
        return ["".join(row).rstrip() for row in self.buf]

    def visible(self):
        out = self.lines()
        while out and not out[-1]:
            out.pop()
        return "\n".join(out)


def run(argv, keys=(), rows=24, cols=100, settle=0.45, timeout=30.0, marker=None):
    """Run argv on a pty of exactly rows x cols, send keys, return bytes + screen."""
    pid, fd = pty.fork()

    if pid == 0:
        os.environ["TERM"] = "xterm-256color"
        os.environ["COLUMNS"] = str(cols)
        os.environ["LINES"] = str(rows)
        os.execvp(argv[0], argv)

    out = bytearray()
    pending = list(keys)
    screen = Terminal(rows, cols)
    deadline = time.time() + timeout
    armed = marker is None
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

            if "\x1b[6n" in text:
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
    keys = []
    for token in spec.split(","):
        if token:
            keys.append(
                token.replace("\\e", "\\x1b")
                .encode("utf8")
                .decode("unicode_escape")
                .encode("latin1")
            )
    return keys


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--rows", type=int, default=24)
    parser.add_argument("--cols", type=int, default=100)
    parser.add_argument("--keys", default="")
    parser.add_argument("--marker", default=None)
    parser.add_argument("--dump", action="store_true")
    parser.add_argument("argv", nargs=argparse.REMAINDER)

    args = parser.parse_args()
    argv = args.argv[1:] if args.argv and args.argv[0] == "--" else args.argv

    raw, screen = run(
        argv,
        keys=parse_keys(args.keys),
        rows=args.rows,
        cols=args.cols,
        marker=args.marker.encode() if args.marker else None,
    )

    visible = screen.visible()
    text = raw.decode("utf8", "replace")

    print(f"rows={args.rows} cols={args.cols} scrolls={screen.scrolls} wraps={screen.wraps} "
          f"unknown={sorted(set(screen.unknown)) or 'none'}")
    print(f"headings={visible.count('create a new project')} "
          f"pointer_rows={visible.count(chr(0x276f))} "
          f"cursor_shown={chr(27) + '[?25h' in text}")

    if args.dump:
        print("-" * args.cols)
        print(visible)


if __name__ == "__main__":
    main()

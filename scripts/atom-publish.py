#!/usr/bin/python3 -I
"""Restricted publication entry point for the CI deploy identity (see docs/deploy-artifact.md).

Runs as root through a single sudoers rule that admits no arguments. Every decision comes from stdin, never from
argv, the environment or the SSH command line:

    first line:  "check <sha256>\\n" or "deploy <sha256>\\n"
    rest:        the artifact built by scripts/build-artifact.py

The artifact is copied into a private root-owned directory and its SHA-256 checked before the installed deployer is
called with fixed options. The deployer and this file are installed by an administrator, root-owned; the CI identity
can neither replace them nor choose the AtoM root, PHP binary, runtime user or services.
"""
import fcntl
import hashlib
import os
import re
import signal
import subprocess
import sys
import tempfile

DEPLOYER = '/usr/local/lib/atom-publish/deploy-artifact.py'
ATOM_ROOT = '/usr/share/nginx/atom'
WORKER_SERVICE = 'atom-worker'
MAX_BYTES = 64 * 1024 * 1024
HEADER_SECONDS, UPLOAD_SECONDS = 30, 600  # a client that never sends (sftp, scp, idle) is disconnected
HEADER = re.compile(rb'(check|deploy) ([0-9a-f]{64})\n')


def fail(message):
    print(f'atom-publish: ERROR: {message}', file=sys.stderr, flush=True)
    sys.exit(64)


def receive(source, path, expected):
    sha, size = hashlib.sha256(), 0
    with open(path, 'xb') as out:
        while chunk := source.read(1 << 20):
            size += len(chunk)
            if size > MAX_BYTES:
                fail(f'artifact larger than {MAX_BYTES} bytes')
            sha.update(chunk)
            out.write(chunk)
    if not size or sha.hexdigest() != expected:
        fail('artifact is empty or does not match the SHA-256 in the header')


def main():
    if len(sys.argv) != 1:
        fail('no arguments are accepted')
    if os.geteuid() != 0:
        fail('must be run through its sudoers rule')
    signal.signal(signal.SIGALRM, lambda *_: fail('timed out waiting for input'))
    signal.alarm(HEADER_SECONDS)
    source = sys.stdin.buffer
    header = HEADER.fullmatch(source.readline(80))
    if not header:
        fail('expected first line "check <sha256>" or "deploy <sha256>"')
    mode, sha = header.group(1).decode(), header.group(2).decode()
    # One publication at a time, before accepting data: concurrent connections cannot pile up uploads.
    lock = os.open(DEPLOYER, os.O_RDONLY | os.O_CLOEXEC)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        fail('another publication is in progress')
    with tempfile.TemporaryDirectory(prefix='atom-publish-') as tmp:  # mode 0700, owned by root
        archive = os.path.join(tmp, 'artifact.tar.gz')
        signal.alarm(UPLOAD_SECONDS)
        receive(source, archive, sha)
        signal.alarm(0)
        command = ['/usr/bin/python3', '-I', DEPLOYER, archive, '--sha256', sha, '--atom-root', ATOM_ROOT]
        if mode == 'check':
            command.append('--check-only')
        else:
            # Documented step before every intentional publication: reset the worker start limit counter.
            subprocess.run(['/usr/bin/systemctl', 'reset-failed', WORKER_SERVICE], check=True)
        result = subprocess.run(command, stdin=subprocess.DEVNULL)
    sys.exit(result.returncode)


if __name__ == '__main__':
    main()

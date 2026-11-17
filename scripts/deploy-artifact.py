#!/usr/bin/env python3
"""Apply a verified code artifact to an existing native AtoM 2.10.2 installation."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import re
import shutil
import subprocess
import tarfile
import tempfile

ATOM_COMMIT = '02a70b8a4b23a805256abd0a14cd0f93e311a581'
PLUGIN = 'plugins/arUnicaucaB5Plugin'
DIRECTORIES = (PLUGIN, 'dist')
TEMPLATES = (
    f'{PLUGIN}/templates/_layout_start.php',
    'plugins/arDominionB5Plugin/templates/_layout_start.php',
    'config/unavailableB5.php',
    'config/error/errorB5.html.php',
)
FILES = TEMPLATES[1:]
SURFACES = ([{'path': p, 'kind': 'directory'} for p in DIRECTORIES]
            + [{'path': p, 'kind': 'file'} for p in FILES])


def run(*args, cwd=None, capture=False):
    return subprocess.run(args, cwd=cwd, check=True, text=True,
                          stdout=subprocess.PIPE if capture else None).stdout


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def allowed(path):
    return path in FILES or any(path.startswith(p + '/') for p in DIRECTORIES)


def inventory(tree):
    files = {}
    for p in tree.rglob('*'):
        if p.is_symlink() or not (p.is_dir() or p.is_file()):
            raise ValueError(f'Unsupported entry: {p}')
        if p.is_file():
            rel = p.relative_to(tree).as_posix()
            if not allowed(rel):
                raise ValueError(f'Unauthorized file: {rel}')
            files[rel] = digest(p)
    return files


def validate_payload(tree, manifest):
    if not isinstance(manifest, dict):
        raise ValueError('Manifest must be an object')
    if manifest.get('format') != 1 or manifest.get('surfaces') != SURFACES:
        raise ValueError('Unsupported manifest format or surfaces')
    if manifest.get('atom_commit') != ATOM_COMMIT:
        raise ValueError('Artifact is not for the pinned AtoM 2.10.2')
    if not re.fullmatch(r'[0-9a-f]{40}', manifest.get('project_commit', '')):
        raise ValueError('Invalid source commit')
    files = inventory(tree)
    if not files or files != manifest.get('sha256'):
        raise ValueError('Payload does not match manifest hashes')
    essential = [f'{PLUGIN}/config/arUnicaucaB5PluginConfiguration.class.php', *TEMPLATES]
    for rel in essential:
        if rel not in files or not (tree / rel).stat().st_size:
            raise ValueError(f'Missing essential file: {rel}')
    for rel in TEMPLATES:
        refs = set(re.findall(r'/dist/[^\s\x22\x27<>]+\.(?:js|css)', (tree / rel).read_text()))
        theme = 'arUnicaucaB5Plugin' if rel == TEMPLATES[0] else 'arDominionB5Plugin'
        required = [(theme, 'css')]
        if rel.startswith('plugins/'):
            required += [('vendor', 'js'), (theme, 'js')]
        for name, ext in required:
            if not any(re.search(rf'/{name}\.bundle\.[^/]+\.{ext}$', ref) for ref in refs):
                raise ValueError(f'Missing {name}.{ext} reference: {rel}')
        for ref in refs:
            if ref[1:] not in files or not (tree / ref[1:]).stat().st_size:
                raise ValueError(f'Missing bundle: {ref}')
    return files


def unpack(archive, expected_hash, stage):
    if digest(archive) != expected_hash:
        raise ValueError('Archive SHA-256 mismatch')
    parents = {str(p) for surface in SURFACES
               for p in PurePosixPath(surface['path']).parents if str(p) != '.'}
    with tarfile.open(archive, 'r:gz') as tar:
        seen = set()
        for member in tar.getmembers():
            name = member.name
            if (not name or str(PurePosixPath(name)) != name
                    or name.startswith('/') or '..' in PurePosixPath(name).parts
                    or name in seen):
                raise ValueError(f'Invalid/duplicate archive path: {name}')
            seen.add(name)
            if member.isfile():
                valid = name == 'manifest.json' or allowed(name)
            elif member.isdir():
                valid = name in parents or name in DIRECTORIES or any(name.startswith(p + '/') for p in DIRECTORIES)
            else:
                valid = False
            if not valid:
                raise ValueError(f'Unauthorized archive entry: {name}')
        tar.extractall(stage, filter='data')
    manifest = json.loads((stage / 'manifest.json').read_text())
    # Keep metadata outside the runtime payload and inventory.
    (stage / 'manifest.json').unlink()
    validate_payload(stage, manifest)
    return manifest


def check_target(root):
    if root != root.resolve() or not root.is_dir() or root == Path('/'):
        raise ValueError('Use an existing canonical absolute AtoM root without symlinks')
    for rel in (*DIRECTORIES, *FILES, 'cache', 'config/config.php', 'symfony',
                'apps/qubit/config/qubitConfiguration.class.php'):
        path = root / rel
        for component in (path, *path.parents):
            if component == root:
                break
            if component.is_symlink():
                raise ValueError(f'Symlink in target path: {component}')
        if rel in DIRECTORIES and path.exists():
            if not path.is_dir():
                raise ValueError(f'Expected directory: {path}')
            if any(os.path.ismount(p) for p in (path, *[p for p in path.rglob('*') if p.is_dir()])):
                raise ValueError(f'Mount inside managed directory: {path}')
        elif rel in FILES:
            if not path.parent.is_dir() or (path.exists() and not path.is_file()):
                raise ValueError(f'Invalid generated file destination: {path}')
        elif not path.exists():
            raise ValueError(f'Not an installed AtoM tree: missing {path}')
    version = (root / 'apps/qubit/config/qubitConfiguration.class.php').read_text()
    if not re.search(r"const VERSION\s*=\s*'2\.10\.2'", version):
        raise ValueError('Installed AtoM version must be 2.10.2')


def verify_installed(root, expected):
    actual = {}
    for rel in DIRECTORIES:
        for p in (root / rel).rglob('*'):
            if p.is_symlink():
                raise ValueError(f'Symlink in installed code: {p}')
            if p.is_file():
                actual[p.relative_to(root).as_posix()] = digest(p)
    actual.update({rel: digest(root / rel) for rel in FILES})
    if actual != expected:
        raise ValueError('Installed files differ from artifact (including deletions)')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('archive', type=Path)
    parser.add_argument('--sha256', required=True, help='SHA-256 obtained from the local build')
    parser.add_argument('--atom-root', type=Path, required=True)
    parser.add_argument('--runtime-user', default='www-data')
    parser.add_argument('--php', default='/usr/bin/php8.3')
    parser.add_argument('--web-service', default='nginx')
    parser.add_argument('--php-service', default='php8.3-fpm')
    parser.add_argument('--worker-service', default='atom-worker')
    parser.add_argument('--check-only', action='store_true', help='Validate without stopping services or writing code')
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise ValueError('Run as root (sudo) on the target installation')
    if not re.fullmatch(r'[0-9a-f]{64}', args.sha256):
        raise ValueError('Invalid SHA-256')
    root = args.atom_root.absolute()
    account = pwd.getpwnam(args.runtime_user)
    services = [args.web_service, args.php_service, args.worker_service]
    if len(set(services)) != 3 or any(not re.fullmatch(r'[A-Za-z0-9@_.-]+', s) or s.startswith('-') for s in services):
        raise ValueError('Specify three distinct application service names')
    for command in ('rsync', 'systemctl', 'runuser', args.php):
        if not shutil.which(command):
            raise ValueError(f'Missing command: {command}')
    check_target(root)
    # An advisory directory lock needs no persistent lock file in the installation.
    lock = os.open(root, os.O_RDONLY)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix='atom-code-') as tmp:
            stage = Path(tmp)
            manifest = unpack(args.archive, args.sha256, stage)
            php_files = sorted(str(p) for p in stage.rglob('*.php'))
            run(args.php, '-l', *php_files, capture=True)
            for service in services:
                state = run('systemctl', 'show', '--property=LoadState', '--value', service, capture=True).strip()
                if state != 'loaded':
                    raise ValueError(f'Service must be loaded and unmasked: {service} ({state})')
            print(f"Validated source {manifest['project_commit']}; {len(manifest['sha256'])} files", flush=True)
            if args.check_only:
                print('Check only: no services stopped and no installed files changed')
                return
            stopped = False
            try:
                stopped = True
                run('systemctl', 'stop', *services)
                for rel in (*DIRECTORIES, *FILES):
                    source = str(stage / rel) + ('/' if rel in DIRECTORIES else '')
                    target = str(root / rel) + ('/' if rel in DIRECTORIES else '')
                    run('rsync', '-a', '--delete', f'--chown={account.pw_uid}:{account.pw_gid}',
                        '--chmod=Du=rwx,Dgo=rx,Fu=rwX,Fgo=rX', source, target)
                verify_installed(root, manifest['sha256'])
                run('runuser', '-u', args.runtime_user, '--', args.php, 'symfony', 'cc', cwd=root)
                run('systemctl', 'start', args.php_service, args.worker_service)
                run('systemctl', 'start', args.web_service)
                for service in services:
                    run('systemctl', 'is-active', '--quiet', service)
                stopped = False
            finally:
                if stopped:
                    subprocess.run(['systemctl', 'stop', *services], check=False)
                    print('ERROR: update incomplete; application services left stopped. Inspect, correct and rerun.', flush=True)
            print(f"OK: installed source {manifest['project_commit']}; archive SHA256 {args.sha256}")

    finally:
        os.close(lock)


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, KeyError, subprocess.CalledProcessError, tarfile.TarError) as error:
        raise SystemExit(f'ERROR: {error}')

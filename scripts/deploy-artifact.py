#!/usr/bin/env python3
"""Apply a verified code artifact to an existing native AtoM 2.10.2 installation."""
import argparse
import errno
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import re
import shutil
import stat
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
# Runtime state: the only places where the runtime user may write. Everything else is code owned by root.
CACHE_DIRS = ('cache', 'log')      # cache is emptied on apply; top-level log files are handed back to the runtime
STATE_DIRS = ('uploads', 'downloads')  # persistent: only the directory itself is touched, never its contents
RUNTIME_DIRS = CACHE_DIRS + STATE_DIRS
# Files with credentials. They are identified by path, never by the mode they arrive with (tools:install and
# friends may leave them 0644). Group access exists only where the runtime reads the file (verified with the web,
# `symfony cc`, the worker and tools:run): those are root:<runtime group> 0640. appChallenge.yml holds the cookie
# signing salt and is read by index.php (lib/challenge/filter.php). The two that only INSTALL needs
# (run as root, before publishing) stay root:root 0600.
SECRET_FILES = ('config/config.php', 'apps/qubit/config/settings.yml', 'config/appChallenge.yml')
ROOT_SECRET_FILES = ('config/propel.ini', 'apps/qubit/config/settings.yml.tmpl')
ACL_ATTRS = ('system.posix_acl_access', 'system.posix_acl_default')
# Privileged changes act on a descriptor of the inode that was checked: a final symlink is not followed, FIFOs do not
# block and no controlling terminal is acquired.
OPEN_ENTRY = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_NOCTTY | os.O_CLOEXEC
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
    for name in RUNTIME_DIRS:
        path = root / name
        if path.is_symlink() or (path.exists() and not path.is_dir()):
            raise ValueError(f'Runtime state path must be a real directory, not a symlink or file: {path}')
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


def _acl_attrs(target):
    """ACL attributes of a descriptor or of a path (not followed). A filesystem without xattr support cannot have any;
    every other failure (EACCES, EIO, ...) is an unverified state, not an absence, and propagates."""
    try:
        names = os.listxattr(target) if isinstance(target, int) else os.listxattr(target, follow_symlinks=False)
    except OSError as error:
        if error.errno == errno.ENOTSUP:
            return []
        raise
    return [a for a in names if a in ACL_ATTRS]


def _mounts_in_code(root):
    """Mount points inside the installation, except the runtime directories themselves and anything below uploads/
    or downloads/, whose contents are never walked. Code is walked and cache/ is emptied, so a mount there would let
    this script change another filesystem."""
    real = os.path.realpath(root)
    own_volume = [os.path.join(real, d) for d in RUNTIME_DIRS]
    persistent = [os.path.join(real, d) + os.sep for d in STATE_DIRS]
    found = []
    with open('/proc/self/mountinfo', 'rb') as table:
        for line in table:
            point = os.fsdecode(re.sub(rb'\\([0-7]{3})', lambda m: bytes([int(m.group(1), 8)]), line.split()[4]))
            if (point.startswith(real + os.sep) and point not in own_volume
                    and not any(point.startswith(p) for p in persistent)):
                found.append(point)
    return found


def _walk_code(root):
    """Yield the root and every path of the code tree, without descending into runtime directories. Read-only: used
    to inspect; privileged changes go through apply_permissions. It refuses to start if a mount point is inside."""
    mounts = _mounts_in_code(root)
    if mounts:
        raise ValueError(f'Mount point inside the code tree or below cache/ or log/: {mounts[0]}')
    yield root
    for current, dirs, files in os.walk(root):
        if Path(current) == root:
            dirs[:] = [d for d in dirs if d not in RUNTIME_DIRS]
        for name in (*dirs, *files):
            yield Path(current, name)


def _code_target(st, rel, gid):
    """(gid, mode) required for a non-symlink code entry (rel: path relative to the root). Code is root:root, readable
    by everyone and writable by nobody else: directories 0755, files 0644 (0755 if they had any execute bit).
    Credentials follow their contract. Any other entry closed to 'other' is refused instead of being opened to
    everyone or silently closed to the runtime: it must be classified first (none exists in an AtoM install)."""
    if rel in SECRET_FILES or rel in ROOT_SECRET_FILES:
        if not stat.S_ISREG(st.st_mode):
            raise ValueError(f'{rel}: credentials file must be a regular file')
        return (gid, 0o640) if rel in SECRET_FILES else (0, 0o600)
    if stat.S_ISDIR(st.st_mode):
        if st.st_mode & 0o005 != 0o005:
            raise ValueError(f'{rel}: directory closed to others is not a known credentials path')
        return 0, 0o755
    if not stat.S_ISREG(st.st_mode):
        raise ValueError(f'{rel}: unsupported file type in the code tree')
    if not st.st_mode & 0o004:
        raise ValueError(f'{rel}: file closed to others is not a known credentials file')
    return 0, 0o755 if st.st_mode & 0o111 else 0o644


def inspect_tree(root, account):
    """Read-only. Return (refusals, deviations): entries the model cannot handle (symlinks that leave the installation
    or point into runtime directories, entries _code_target refuses) and differences that apply_permissions corrects.
    Both empty means the runtime user cannot alter code."""
    uid, gid = account.pw_uid, account.pw_gid
    real = os.path.realpath(root)
    runtime = [os.path.join(real, d) for d in RUNTIME_DIRS]
    refusals, deviations = [], []
    for path in _walk_code(root):
        st = path.lstat()
        rel = path.relative_to(root).as_posix()
        if stat.S_ISLNK(st.st_mode):
            target = os.path.realpath(path)
            if (not (target == real or target.startswith(real + os.sep))
                    or any(target == r or target.startswith(r + os.sep) for r in runtime)):
                refusals.append(f'{rel}: symlink leaves the installation or points into runtime state')
            elif (st.st_uid, st.st_gid) != (0, 0):
                deviations.append(f'{rel}: symlink owner {st.st_uid}:{st.st_gid}')
            continue
        try:
            group, mode = _code_target(st, rel, gid)
        except ValueError as error:
            refusals.append(str(error))
            continue
        actual = (st.st_uid, st.st_gid, st.st_mode & 0o7777)
        if actual != (0, group, mode) or _acl_attrs(path):
            deviations.append(f'{rel}: {actual[0]}:{actual[1]} {actual[2]:o}, expected 0:{group} {mode:o} without ACL')
    for name in RUNTIME_DIRS:
        path = root / name
        st = path.lstat() if path.exists() or path.is_symlink() else None
        if (not st or not stat.S_ISDIR(st.st_mode) or (st.st_uid, st.st_gid) != (uid, gid)
                or (name in CACHE_DIRS and st.st_mode & 0o7777 != 0o750)):
            deviations.append(f'{name}: runtime directory missing or not {uid}:{gid}')
    if (root / 'log').is_dir() and not (root / 'log').is_symlink():
        for p in (root / 'log').iterdir():
            st = p.lstat()
            if not stat.S_ISREG(st.st_mode):
                continue
            if st.st_nlink != 1:  # _own_logs refuses to hand it to the runtime
                refusals.append(f'log/{p.name}: regular file with {st.st_nlink} links, expected a single link')
            elif (st.st_uid, st.st_gid, st.st_mode & 0o7777) != (uid, gid, 0o640):
                deviations.append(f'log/{p.name}: {st.st_uid}:{st.st_gid} {st.st_mode & 0o7777:o}, '
                                  f'expected {uid}:{gid} 640')
    return refusals, deviations


def _converge(fd, rel, gid):
    group, mode = _code_target(os.fstat(fd), rel, gid)
    for name in _acl_attrs(fd):
        os.removexattr(fd, name)
    os.fchown(fd, 0, group)
    os.fchmod(fd, mode)


def apply_permissions(root, account):
    """Converge the installation to the model. On first adoption the tree may still belong to the runtime user, so no
    privileged change goes through a path it can redirect: every directory becomes root-owned (no write for others)
    before it is listed, so its entries can no longer be renamed or added; each entry is then opened relative to its
    parent's descriptor without following symlinks and changed through that descriptor, after fstat."""
    uid, gid = account.pw_uid, account.pw_gid
    mounts = _mounts_in_code(root)
    if mounts:
        raise ValueError(f'Mount point inside the code tree or below cache/ or log/: {mounts[0]}')
    top = os.open(root, OPEN_ENTRY | os.O_DIRECTORY)
    try:
        _converge(top, '.', gid)
        # Top-down: the generator lists a directory only after this loop has converged it as a child of its parent.
        for current, dirs, files, dir_fd in os.fwalk('.', dir_fd=top):
            if current == '.':
                dirs[:] = [d for d in dirs if d not in RUNTIME_DIRS]
            for name in (*dirs, *files):
                if stat.S_ISLNK(os.lstat(name, dir_fd=dir_fd).st_mode):
                    os.chown(name, 0, 0, dir_fd=dir_fd, follow_symlinks=False)
                    continue
                fd = os.open(name, OPEN_ENTRY, dir_fd=dir_fd)
                try:
                    _converge(fd, os.path.normpath(os.path.join(current, name)), gid)
                finally:
                    os.close(fd)
        # Runtime directories: created if missing (the runtime can no longer create entries in the root).
        for name in RUNTIME_DIRS:
            try:
                os.mkdir(name, 0o750 if name in CACHE_DIRS else 0o755, dir_fd=top)
            except FileExistsError:
                pass
            fd = os.open(name, OPEN_ENTRY | os.O_DIRECTORY, dir_fd=top)
            try:
                os.fchown(fd, uid, gid)
                if name in CACHE_DIRS:
                    os.fchmod(fd, 0o750)
                if name == 'cache':
                    _empty(fd)
                elif name == 'log':
                    _own_logs(fd, uid, gid)
            finally:
                os.close(fd)
    finally:
        os.close(top)


def _empty(dir_fd):
    """Remove the disposable cache (it may hold files of a task run as root, which the runtime cannot replace).
    shutil.rmtree on a descriptor refuses symlinks swapped in during removal; mounts below cache/ were refused."""
    for name in os.listdir(dir_fd):
        if stat.S_ISDIR(os.lstat(name, dir_fd=dir_fd).st_mode):
            shutil.rmtree(name, dir_fd=dir_fd)
        else:
            os.unlink(name, dir_fd=dir_fd)


def _own_logs(dir_fd, uid, gid):
    """Hand top-level log files back to the runtime as 0640 (a task run as root leaves them root-owned and the
    runtime could no longer append). Through a descriptor and only for a single link: a hard link to a file elsewhere
    must never be given to the runtime user."""
    for name in os.listdir(dir_fd):
        if not stat.S_ISREG(os.lstat(name, dir_fd=dir_fd).st_mode):
            continue
        fd = os.open(name, OPEN_ENTRY, dir_fd=dir_fd)
        try:
            st = os.fstat(fd)
            if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1:
                raise ValueError(f'log/{name}: expected a regular file with a single link')
            os.fchown(fd, uid, gid)
            os.fchmod(fd, 0o640)
        finally:
            os.close(fd)


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
            refusals, deviations = inspect_tree(root, account)
            if refusals:
                raise ValueError('Permission model cannot be applied: ' + '; '.join(refusals[:5]))
            if args.check_only:
                print(f'Permission model: {len(deviations)} deviation(s) to be corrected on apply' if deviations
                      else 'Permission model: already satisfied')
                print('Check only: no services stopped and no installed files changed')
                return
            stopped = False
            try:
                stopped = True
                run('systemctl', 'stop', *services)
                # Permissions first: rsync then writes as root only into directories the runtime cannot modify,
                # and its --chown/--chmod produce exactly the modes of the model.
                apply_permissions(root, account)
                for rel in (*DIRECTORIES, *FILES):
                    source = str(stage / rel) + ('/' if rel in DIRECTORIES else '')
                    target = str(root / rel) + ('/' if rel in DIRECTORIES else '')
                    run('rsync', '-a', '--delete', '--chown=0:0',
                        '--chmod=Du=rwx,Dgo=rx,Fu=rwX,Fgo=rX', source, target)
                verify_installed(root, manifest['sha256'])
                refusals, deviations = inspect_tree(root, account)
                if refusals or deviations:
                    raise ValueError('Permission model violated: ' + '; '.join((refusals + deviations)[:5]))
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

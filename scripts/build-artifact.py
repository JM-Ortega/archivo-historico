#!/usr/bin/env python3
"""Build and verify a code artifact from committed sources for AtoM 2.10.2."""
import argparse
import gzip
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[1]
UPSTREAM = ROOT / 'upstream/atom'
ATOM_COMMIT = '02a70b8a4b23a805256abd0a14cd0f93e311a581'
PLUGIN = 'plugins/arUnicaucaB5Plugin'
GENERATED = (
    f'{PLUGIN}/templates/_layout_start.php',
    'plugins/arDominionB5Plugin/templates/_layout_start.php',
    'config/unavailableB5.php',
    'config/error/errorB5.html.php',
)
SURFACES = [
    {'path': PLUGIN, 'kind': 'directory'},
    {'path': 'dist', 'kind': 'directory'},
    *[{'path': p, 'kind': 'file'} for p in GENERATED[1:]],
]
NODE = 'v22.23.3'
NPM = '10.9.9'


def run(*args, cwd=ROOT, capture=False, env=None):
    return subprocess.run(args, cwd=cwd, check=True, env=env,
                          stdout=subprocess.PIPE if capture else None).stdout


def git(*args, cwd=ROOT):
    return run('git', *args, cwd=cwd, capture=True).decode().strip()


def extract_revision(repo, revision, destination, *paths, allow_links=False):
    # git archive ignores working-tree changes, ignored files and submodule contents.
    archive = run('git', 'archive', '--format=tar', revision, *paths,
                  cwd=repo, capture=True)
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        for member in tar.getmembers():
            if not allow_links and (member.issym() or member.islnk()):
                raise ValueError(f'Symlinks/links are not supported: {member.name}')
        tar.extractall(destination, filter='data')


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def allowed(path):
    return (path.startswith(PLUGIN + '/') or path.startswith('dist/')
            or path in GENERATED[1:])


def inventory(tree):
    result = {}
    for path in sorted(tree.rglob('*')):
        if path.is_symlink() or not (path.is_file() or path.is_dir()):
            raise ValueError(f'Unsupported payload entry: {path}')
        if path.is_file():
            rel = path.relative_to(tree).as_posix()
            if not allowed(rel):
                raise ValueError(f'Unauthorized payload: {rel}')
            result[rel] = digest(path)
    return result


def verify(tree, source_files):
    files = inventory(tree)
    for rel, expected in source_files.items():
        if files.get(rel) != expected:
            raise ValueError(f'Incomplete/modified plugin source: {rel}')
    if set(p for p in files if p.startswith(PLUGIN + '/')) != set(source_files) | {GENERATED[0]}:
        raise ValueError('Unexpected plugin files')
    for rel in GENERATED:
        template = tree / rel
        if not template.is_file() or template.stat().st_size == 0:
            raise ValueError(f'Missing generated template: {rel}')
        refs = set(re.findall(r'/dist/[^\s\x22\x27<>]+\.(?:js|css)', template.read_text()))
        theme = 'arUnicaucaB5Plugin' if rel == GENERATED[0] else 'arDominionB5Plugin'
        required = [(theme, 'css')]
        if rel.startswith('plugins/'):
            required += [('vendor', 'js'), (theme, 'js')]
        for name, ext in required:
            if not any(re.search(rf'/{name}\.bundle\.[^/]+\.{ext}$', ref) for ref in refs):
                raise ValueError(f'Missing {name}.{ext} reference: {rel}')
        for ref in refs:
            asset = tree / ref.lstrip('/')
            if '..' in Path(ref).parts or not asset.is_file() or asset.stat().st_size == 0:
                raise ValueError(f'Missing/unsafe bundle: {ref}')
    # Webpack emits fonts, icons, images and source maps as well as bundles.
    if not any(p.startswith('dist/') for p in files):
        raise ValueError('Empty dist')
    return files


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('revision', nargs='?', default='HEAD')
    parser.add_argument('--output', type=Path, default=ROOT / 'tmp/artifacts')
    args = parser.parse_args()
    commit = git('rev-parse', '--verify', args.revision + '^{commit}')
    entry = git('ls-tree', commit, 'upstream/atom').split()
    if entry[:2] != ['160000', 'commit'] or entry[2] != ATOM_COMMIT:
        raise ValueError('Revision does not pin the authorized AtoM 2.10.2 commit')
    git('cat-file', '-e', ATOM_COMMIT + '^{commit}', cwd=UPSTREAM)
    for command, expected in [('node', NODE), ('npm', NPM)]:
        actual = run(command, '--version', capture=True).decode().strip()
        if actual != expected:
            raise ValueError(f'Requires {command} {expected}; found {actual}')
    args.output.mkdir(parents=True, exist_ok=True)
    output = args.output.resolve() / f'archivo-historico-{commit}.tar.gz'
    if output.exists():
        raise ValueError(f'Refusing to overwrite: {output}')
    epoch = int(git('show', '-s', '--format=%ct', commit))
    with tempfile.TemporaryDirectory(prefix='archivo-build-') as tmp:
        work = Path(tmp)
        atom = work / 'atom'
        atom.mkdir()
        extract_revision(UPSTREAM, ATOM_COMMIT, atom, allow_links=True)
        extract_revision(ROOT, commit, atom, PLUGIN)
        source_files = inventory_plugin(atom)
        if GENERATED[0] in source_files:
            raise ValueError('Generated template must not be versioned')
        env = dict(os.environ, NODE_ENV='production', TZ='UTC', LC_ALL='C',
                   SOURCE_DATE_EPOCH=str(epoch), npm_config_cache=str(work / 'npm-cache'))
        # Lockfile dependencies only. No lifecycle scripts (including Cypress download).
        run('npm', 'ci', '--include=dev', '--ignore-scripts', '--no-audit', '--no-fund', cwd=atom, env=env)
        # Do not accept any pre-existing compiled output, even in a future source revision.
        shutil.rmtree(atom / 'dist', ignore_errors=True)
        for rel in GENERATED:
            (atom / rel).unlink(missing_ok=True)
        run('npm', 'run', 'build', cwd=atom, env=env)
        payload = work / 'payload'
        payload.mkdir()
        for surface in SURFACES:
            rel = surface['path']
            dest = payload / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            if surface['kind'] == 'directory':
                shutil.copytree(atom / rel, dest)
            else:
                shutil.copyfile(atom / rel, dest)
        files = verify(payload, source_files)
        manifest = {'format': 1, 'project_commit': commit, 'atom_commit': ATOM_COMMIT,
                    'node': NODE, 'npm': NPM, 'surfaces': SURFACES, 'sha256': files}
        (payload / 'manifest.json').write_text(json.dumps(manifest, indent=2, sort_keys=True) + '\n')
        # Stable order, permissions, ownership, mtimes and gzip header.
        with tempfile.NamedTemporaryFile(dir=args.output, suffix='.partial', delete=False) as temp:
            partial = Path(temp.name)
        try:
            with partial.open('wb') as raw, gzip.GzipFile(filename='', fileobj=raw, mode='wb', mtime=0) as gz:
                with tarfile.open(fileobj=gz, mode='w', format=tarfile.USTAR_FORMAT) as tar:
                    for path in sorted(payload.rglob('*')):
                        rel = path.relative_to(payload).as_posix()
                        info = tar.gettarinfo(str(path), arcname=rel)
                        info.uid = info.gid = 0
                        info.uname = info.gname = ''
                        info.mtime = epoch
                        info.mode = 0o755 if path.is_dir() or path.stat().st_mode & 0o111 else 0o644
                        if path.is_file():
                            with path.open('rb') as stream:
                                tar.addfile(info, stream)
                        else:
                            tar.addfile(info)
            # Read the actual archive back before publishing the output filename.
            check = work / 'check'
            check.mkdir()
            with tarfile.open(partial) as tar:
                tar.extractall(check, filter='data')
            packed = json.loads((check / 'manifest.json').read_text())
            (check / 'manifest.json').unlink()
            if verify(check, source_files) != packed['sha256'] or packed != manifest:
                raise ValueError('Archive readback mismatch')
            os.replace(partial, output)
        finally:
            partial.unlink(missing_ok=True)
    print(f'OK: {output}\n{output.stat().st_size} bytes; {len(files)} payload files\nSHA256 {digest(output)}')


def inventory_plugin(atom):
    return {p.relative_to(atom).as_posix(): digest(p)
            for p in (atom / PLUGIN).rglob('*') if p.is_file()}


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, subprocess.CalledProcessError, tarfile.TarError) as error:
        raise SystemExit(f'ERROR: {error}')

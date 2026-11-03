// Erzeugt public/assets/app.css aus app/styles/app.css (Tailwind, gepinnt in tests/package.json)
// und legt die selbst gehosteten Schriften ab. Aufruf: node scripts/build-css.cjs [--watch]
const fs = require('node:fs'), path = require('node:path'), {spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const modules = path.join(root, 'tests/node_modules');
const fonts = path.join(root, 'public/assets/vendor/fonts');
fs.mkdirSync(fonts, {recursive:true});
for (const [pkg, prefix] of [['geist', 'geist'], ['geist-mono', 'geist-mono']]) {
    const source = path.join(modules, '@fontsource-variable', pkg);
    for (const subset of ['latin', 'latin-ext']) {
        const name = `${prefix}-${subset}-wght-normal.woff2`;
        fs.copyFileSync(path.join(source, 'files', name), path.join(fonts, name));
    }
    fs.copyFileSync(path.join(source, 'LICENSE'), path.join(fonts, `${prefix}-LICENSE`));
}
const cli = path.join(modules, '@tailwindcss/cli/dist/index.mjs');
const args = [cli, '-i', path.join(root, 'app/styles/app.css'), '-o', path.join(root, 'public/assets/app.css')];
args.push(process.argv.includes('--watch') ? '--watch' : '--minify');
const result = spawnSync(process.execPath, args, {cwd: root, stdio: 'inherit'});
process.exit(result.status ?? 1);

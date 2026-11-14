// Compile resources/css/app.css with Tailwind CSS v4 into public/assets/css/app.css.
// Usage: NODE_PATH=<dir containing tailwindcss> node tools/build-css.mjs   (or: npm i -D tailwindcss@4 && node tools/build-css.mjs)
import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const require = createRequire(path.join(process.env.NODE_PATH || root, 'noop.js'));
const twPkg = path.dirname(require.resolve('tailwindcss/package.json'));
const { compile } = await import(path.join(twPkg, 'dist/lib.mjs'));

function walk(dir, exts, out = []) {
  for (const f of readdirSync(dir)) {
    const p = path.join(dir, f);
    if (statSync(p).isDirectory()) walk(p, exts, out);
    else if (exts.some((e) => p.endsWith(e))) out.push(p);
  }
  return out;
}
const files = [...walk(path.join(root, 'views'), ['.php']), ...walk(path.join(root, 'src'), ['.php']), path.join(root, 'public/assets/js/app.js')];
const candidates = new Set();
for (const f of files) {
  for (const tok of readFileSync(f, 'utf8').split(/[\s"'`<>={}(),;\\]+/)) {
    if (tok && tok.length < 120 && /^[!a-z0-9\-:[\]/.%#_@&>*+]+!?$/i.test(tok)) candidates.add(tok.replace(/^\.|\.$/g, ''));
  }
}
const input = readFileSync(path.join(root, 'resources/css/app.css'), 'utf8');
const compiler = await compile(input, {
  base: root,
  loadStylesheet: async (id, base) => {
    const file = id === 'tailwindcss' ? path.join(twPkg, 'index.css') : path.resolve(base, id);
    return { path: file, base: path.dirname(file), content: readFileSync(file, 'utf8') };
  },
});
let css = compiler.build([...candidates]);
// light minification
css = css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\n\s*/g, '\n').replace(/\n{2,}/g, '\n');
writeFileSync(path.join(root, 'public/assets/css/app.css'), css);
console.log(`✓ app.css ${(css.length / 1024).toFixed(1)} KB from ${candidates.size} candidates`);

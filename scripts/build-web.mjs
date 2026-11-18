// Arma public/ para Vercel: copia SOLO el frontend estático de app/ (nunca los .php ni config)
// e inyecta la guardia de sesión en cada página.
import { cpSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, extname } from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = join(fileURLToPath(import.meta.url), '..', '..');
const app = join(raiz, 'app');
const out = join(raiz, 'public');
const EXT = new Set(['.html', '.js', '.css']);

rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

for (const f of readdirSync(app)) {
  if (!EXT.has(extname(f))) continue;
  let txt = readFileSync(join(app, f), 'utf8');
  if (extname(f) === '.html') {
    txt = txt.includes('</head>')
      ? txt.replace('</head>', '<script src="/auth-guard.js"></script></head>')
      : '<script src="/auth-guard.js"></script>' + txt;
  }
  writeFileSync(join(out, f), txt);
}
cpSync(join(raiz, 'web', 'login.html'), join(out, 'login.html'));
cpSync(join(raiz, 'web', 'admin.html'), join(out, 'admin.html'));
cpSync(join(raiz, 'web', 'auth-guard.js'), join(out, 'auth-guard.js'));
console.log('public/ listo:', readdirSync(out).join(', '));

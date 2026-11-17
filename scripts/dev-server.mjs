// Servidor local que imita Vercel (estáticos de public/ + funciones /api + rewrites de vercel.json).
// Uso: node scripts/dev-server.mjs   (lee .env.web si existe; puerto 3000)
import http from 'node:http';
import { readFileSync, existsSync, statSync } from 'node:fs';
import { join, extname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const raiz = join(fileURLToPath(import.meta.url), '..', '..');
for (const f of ['.env.web', '../../../.env.web']) {
  const p = join(raiz, f);
  if (existsSync(p)) {
    for (const l of readFileSync(p, 'utf8').split(/\r?\n/)) {
      const m = l.match(/^([A-Z_]+)=(.*)$/);
      if (m && !process.env[m[1]]) process.env[m[1]] = m[2].trim();
    }
    break;
  }
}
const cfg = JSON.parse(readFileSync(join(raiz, 'vercel.json'), 'utf8'));
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json' };

function readBody(req) {
  return new Promise((ok) => {
    const ch = [];
    req.on('data', (c) => ch.push(c));
    req.on('end', () => ok(Buffer.concat(ch).toString()));
  });
}

http.createServer(async (req, res) => {
  const u = new URL(req.url, 'http://localhost');
  let path = u.pathname;
  const rw = cfg.rewrites.find((r) => r.source === path);
  if (rw) path = rw.destination;

  if (path.startsWith('/api/')) {
    const file = join(raiz, path + '.js');
    if (!existsSync(file)) { res.statusCode = 404; return res.end('no existe'); }
    req.query = Object.fromEntries(u.searchParams);
    const raw = req.method === 'GET' ? '' : await readBody(req);
    const ct = req.headers['content-type'] || '';
    req.body = raw ? (ct.includes('json') ? JSON.parse(raw) : Object.fromEntries(new URLSearchParams(raw))) : {};
    res.status = (c) => { res.statusCode = c; return res; };
    res.json = (o) => { res.setHeader('Content-Type', 'application/json; charset=utf-8'); res.end(JSON.stringify(o)); };
    try {
      const mod = await import(pathToFileURL(file).href);
      await mod.default(req, res);
    } catch (e) { res.statusCode = 500; res.end(String(e.stack || e)); }
    return;
  }
  const f = join(raiz, 'public', path === '/' ? 'leads_analytics.html' : path);
  if (!f.startsWith(join(raiz, 'public')) || !existsSync(f) || !statSync(f).isFile()) { res.statusCode = 404; return res.end('404'); }
  res.setHeader('Content-Type', MIME[extname(f)] || 'application/octet-stream');
  res.end(readFileSync(f));
}).listen(3000, () => console.log('http://localhost:3000'));

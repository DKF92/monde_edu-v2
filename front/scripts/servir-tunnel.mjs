// Acces https temporaire pour installer l'application sur un telephone
// (une PWA ne s'installe qu'en https). Lancer depuis front/ : npm run tunnel
//
// - sert le build « tunnel » (dist/tunnel/browser) sur http://localhost:8100 ;
// - relaie /api et /storage vers Laravel (http://127.0.0.1:8000) : le front et
//   l'API ont ainsi la meme adresse https (pas de contenu mixte) ;
// - ouvre un tunnel Cloudflare gratuit (cloudflared, sans compte) et affiche
//   l'adresse https a ouvrir sur le telephone. Ctrl+C arrete tout.
import http from 'node:http';
import { spawn } from 'node:child_process';
import { createReadStream, existsSync, statSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const RACINE = fileURLToPath(new URL('../dist/tunnel/browser/', import.meta.url));
const PORT = Number(process.env.PORT_TUNNEL || 8100);
const API = { hote: '127.0.0.1', port: 8000 };
const CLOUDFLARED = process.env.CLOUDFLARED || 'C:/laragon/bin/cloudflared/cloudflared.exe';

const TYPES = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css',
  '.json': 'application/json', '.webmanifest': 'application/manifest+json', '.png': 'image/png', '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.woff2': 'font/woff2', '.webm': 'video/webm', '.txt': 'text/plain',
};

if (!existsSync(join(RACINE, 'index.html'))) {
  console.error('Build absent : lancez d\'abord « ng build --configuration tunnel » (ou npm run tunnel).');
  process.exit(1);
}

function relayer(req, res) {
  const proxy = http.request({
    host: API.hote, port: API.port, method: req.method, path: req.url,
    headers: { ...req.headers, 'x-forwarded-host': req.headers.host, 'x-forwarded-proto': 'https', 'x-forwarded-for': req.socket.remoteAddress },
  }, (r) => { res.writeHead(r.statusCode, r.headers); r.pipe(res); });
  proxy.on('error', () => { res.writeHead(502, { 'Content-Type': 'application/json' }); res.end('{"message":"API Laravel injoignable (port 8000)."}'); });
  req.pipe(proxy);
}

function servir(req, res) {
  const chemin = decodeURIComponent(req.url.split('?')[0]);
  let fichier = normalize(join(RACINE, chemin));
  if (!fichier.startsWith(normalize(RACINE)) || !existsSync(fichier) || statSync(fichier).isDirectory()) {
    fichier = join(RACINE, 'index.html'); // routes Angular
  }
  const ext = extname(fichier);
  // index.html, ngsw.json et le manifeste toujours frais ; le reste est versionne.
  const frais = ['.html', '.json', '.webmanifest'].includes(ext) || fichier.endsWith('ngsw-worker.js');
  res.writeHead(200, { 'Content-Type': TYPES[ext] || 'application/octet-stream', 'Cache-Control': frais ? 'no-cache' : 'public, max-age=31536000, immutable' });
  createReadStream(fichier).pipe(res);
}

http.createServer((req, res) => (/^\/(api|storage)(\/|$)/.test(req.url) ? relayer(req, res) : servir(req, res)))
  .listen(PORT, () => console.log(`Relais : http://localhost:${PORT} (front + /api -> Laravel :8000)`));

if (!existsSync(CLOUDFLARED)) {
  console.error(`cloudflared introuvable (${CLOUDFLARED}) : relais seul, sans tunnel.`);
} else {
  const tunnel = spawn(CLOUDFLARED, ['tunnel', '--no-autoupdate', '--url', `http://localhost:${PORT}`], { stdio: ['ignore', 'pipe', 'pipe'] });
  const lire = (d) => {
    const m = String(d).match(/https:\/\/[a-z0-9-]+\.trycloudflare\.com/);
    if (m) console.log(`\n  Adresse a ouvrir sur le telephone : ${m[0]}\n`);
  };
  tunnel.stdout.on('data', lire);
  tunnel.stderr.on('data', lire);
  const arreter = () => { tunnel.kill(); process.exit(0); };
  process.on('SIGINT', arreter);
  process.on('SIGTERM', arreter);
}

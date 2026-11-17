// El buscador en lenguaje natural (app/nl_search.php) aún no se porta a la web.
import { requireUser } from './_lib/auth.js';

export default async function handler(req, res) {
  if (!(await requireUser(req, res))) return;
  res.status(501).json({ ok: false, error: 'El buscador inteligente aún no está disponible en la versión web.' });
}

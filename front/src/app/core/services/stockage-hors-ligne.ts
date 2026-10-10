/**
 * Petit acces a IndexedDB pour le hors ligne (base « monde_edu_hors_ligne ») :
 * - « reponses » : dernieres reponses GET de l'API (consultation sans reseau) ;
 * - « file »     : saisies pedagogiques en attente d'envoi.
 * Toute erreur (navigation privee, stockage plein) est ignoree : l'application
 * fonctionne alors simplement sans hors ligne.
 */
const BASE = 'monde_edu_hors_ligne';
export type Magasin = 'reponses' | 'file';

let ouverture: Promise<IDBDatabase | null> | null = null;

function base(): Promise<IDBDatabase | null> {
  ouverture ??= new Promise((ok) => {
    try {
      const r = indexedDB.open(BASE, 1);
      r.onupgradeneeded = () => {
        r.result.createObjectStore('reponses', { keyPath: 'cle' });
        r.result.createObjectStore('file', { keyPath: 'id' });
      };
      r.onsuccess = () => ok(r.result);
      r.onerror = () => ok(null);
    } catch {
      ok(null);
    }
  });
  return ouverture;
}

async function transaction<T>(magasin: Magasin, mode: IDBTransactionMode, action: (s: IDBObjectStore) => IDBRequest<T>): Promise<T | null> {
  const db = await base();
  if (!db) return null;
  return new Promise((ok) => {
    try {
      const r = action(db.transaction(magasin, mode).objectStore(magasin));
      r.onsuccess = () => ok(r.result);
      r.onerror = () => ok(null);
    } catch {
      ok(null);
    }
  });
}

export const stockage = {
  lire: <T>(magasin: Magasin, cle: string) => transaction<T>(magasin, 'readonly', (s) => s.get(cle)),
  tout: <T>(magasin: Magasin) => transaction<T[]>(magasin, 'readonly', (s) => s.getAll()).then((l) => l ?? []),
  ecrire: (magasin: Magasin, valeur: object) => transaction(magasin, 'readwrite', (s) => s.put(valeur)),
  supprimer: (magasin: Magasin, cle: string) => transaction(magasin, 'readwrite', (s) => s.delete(cle)),
  vider: (magasin: Magasin) => transaction(magasin, 'readwrite', (s) => s.clear()),
};

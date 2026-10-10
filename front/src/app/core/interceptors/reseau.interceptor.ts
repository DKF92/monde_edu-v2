import { HttpErrorResponse, HttpEvent, HttpHeaders, HttpInterceptorFn, HttpResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Observable, catchError, from, of, switchMap, tap, throwError } from 'rxjs';
import { ReseauService } from '../services/reseau.service';
import { FileAttenteService, REJEU } from '../services/file-attente.service';
import { AuthService } from '../services/auth.service';
import { stockage } from '../services/stockage-hors-ligne';

/**
 * Ecritures pedagogiques possibles sans reseau (mises en attente sur
 * l'appareil). Tout le reste (inscriptions, paiements, classes...) demande le
 * serveur.
 */
const PEDAGOGIE: { methode: string; chemin: RegExp; libelle: string }[] = [
  { methode: 'PUT', chemin: /^\/notes$/, libelle: 'Notes d\'une évaluation' },
  { methode: 'DELETE', chemin: /^\/notes\/evaluation$/, libelle: 'Suppression d\'une évaluation' },
  { methode: 'PUT', chemin: /^\/conduite$/, libelle: 'Notes de conduite' },
  { methode: 'POST', chemin: /^\/absences$/, libelle: 'Appel (absences)' },
  { methode: 'PUT', chemin: /^\/absences\/\d+$/, libelle: 'Modification d\'une absence' },
  { methode: 'PATCH', chemin: /^\/absences\/\d+\/justifier$/, libelle: 'Justification d\'une absence' },
  { methode: 'DELETE', chemin: /^\/absences\/\d+$/, libelle: 'Suppression d\'une absence' },
  { methode: 'POST', chemin: /^\/arret-notes\/(arreter|rouvrir)$/, libelle: 'Arrêt des notes' },
  { methode: 'PUT', chemin: /^\/rapports\/compiles\/\w+\/textes$/, libelle: 'Textes d\'un rapport' },
];

/** Corps renvoye a la page quand une saisie est mise en attente. */
export interface ReponseEnAttente {
  en_attente: true;
  message: string;
}

export const estEnAttente = (r: unknown): r is ReponseEnAttente => !!r && (r as ReponseEnAttente).en_attente === true;

const MAX_REPONSES = 400;
let ecritures = 0;

function horsLigne(message = 'Vous n\'êtes connecté à aucun réseau : cette opération demande le serveur.'): Observable<never> {
  return throwError(() => new HttpErrorResponse({ status: 0, statusText: 'Hors ligne', error: { message, hors_ligne: true } }));
}

async function elaguer(): Promise<void> {
  const toutes = await stockage.tout<{ cle: string; date: string }>('reponses');
  if (toutes.length <= MAX_REPONSES) return;
  const anciennes = toutes.sort((a, b) => a.date.localeCompare(b.date)).slice(0, toutes.length - MAX_REPONSES);
  for (const r of anciennes) await stockage.supprimer('reponses', r.cle);
}

/**
 * Dernier intercepteur (apres l'authentification, pour voir le contexte) :
 * - envoie la requete au serveur choisi par ReseauService (en ligne / local) ;
 * - garde les reponses GET sur l'appareil et les ressert sans reseau ;
 * - sans reseau, met en attente les ecritures pedagogiques et refuse les autres
 *   avec un message clair.
 */
export const reseauInterceptor: HttpInterceptorFn = (req, next) => {
  const reseau = inject(ReseauService);
  const local = reseau.apiLocal();
  const prefixe = req.url.startsWith(reseau.apiEnLigne) ? reseau.apiEnLigne : local && req.url.startsWith(local) ? local : null;
  if (!prefixe) return next(req);
  const chemin = req.url.slice(prefixe.length);
  if (chemin.startsWith('/ping')) return next(req);

  const requete = req.clone({ url: reseau.base() + chemin });
  const auth = inject(AuthService);

  // ---- Lecture : reseau d'abord, copie de l'appareil a defaut
  if (req.method === 'GET') {
    const contexte = ['X-Etablissement-Id', 'X-Annee-Scolaire-Id', 'X-Periode-Id', 'X-Poste-Id'].map((e) => req.headers.get(e) ?? '').join('.');
    const cle = `${auth.user()?.id ?? 0}|${contexte}|${chemin}?${req.params.toString()}`;
    const memorisee = () =>
      from(stockage.lire<{ corps: unknown; date: string }>('reponses', cle)).pipe(
        switchMap((m) => (m ? of(new HttpResponse({ status: 200, body: m.corps, headers: new HttpHeaders({ 'X-Hors-Ligne': m.date }), url: requete.url })) : horsLigne())),
      );
    if (reseau.horsLigne() || req.responseType !== 'json') {
      return reseau.horsLigne() && req.responseType === 'json' ? memorisee() : next(requete);
    }
    return next(requete).pipe(
      tap((ev: HttpEvent<unknown>) => {
        if (ev instanceof HttpResponse && ev.body !== null) {
          void stockage.ecrire('reponses', { cle, corps: ev.body, date: new Date().toISOString() });
          if (++ecritures % 50 === 0) void elaguer();
        }
      }),
      catchError((e: HttpErrorResponse) => {
        if (e.status !== 0) return throwError(() => e);
        reseau.signalerEchec();
        return memorisee();
      }),
    );
  }

  // ---- Ecriture
  if (req.context.get(REJEU)) return next(requete);
  const pedagogie = PEDAGOGIE.find((p) => p.methode === req.method && p.chemin.test(chemin));
  const file = inject(FileAttenteService);
  const mettreEnAttente = () =>
    from(file.ajouter(req, chemin, pedagogie!.libelle)).pipe(
      switchMap(() => of(new HttpResponse<ReponseEnAttente>({
        status: 202,
        body: { en_attente: true, message: 'Hors ligne : saisie gardée sur l\'appareil, elle sera envoyée au retour du réseau.' },
      }))),
    );

  if (reseau.horsLigne()) {
    return pedagogie ? mettreEnAttente() : horsLigne();
  }
  return next(requete).pipe(
    catchError((e: HttpErrorResponse) => {
      if (e.status !== 0) return throwError(() => e);
      reseau.signalerEchec();
      return pedagogie ? mettreEnAttente() : horsLigne();
    }),
  );
};



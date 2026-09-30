import { HttpContextToken, HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from '../services/auth.service';

/**
 * A positionner sur les requetes qui fixent elles-memes leur contexte (ex:
 * ecran de choix de l'espace de travail, qui interroge un etablissement
 * different de l'etablissement actif) : seul le token est alors ajoute.
 */
export const SANS_CONTEXTE = new HttpContextToken<boolean>(() => false);

/**
 * Ajoute a chaque requete API le token Bearer et le contexte courant
 * (etablissement, annee scolaire, trimestre, poste), et redirige vers /login
 * en cas de session expiree (401).
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  const headers: Record<string, string> = {};

  const token = auth.token();
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  if (!req.context.get(SANS_CONTEXTE)) {
    const etablissement = auth.etablissementActif();
    const anneeScolaire = auth.anneeScolaire();
    const periode = auth.periode();
    const poste = auth.poste();

    if (etablissement) {
      headers['X-Etablissement-Id'] = String(etablissement.id);
    }
    if (anneeScolaire) {
      headers['X-Annee-Scolaire-Id'] = String(anneeScolaire.id);
    }
    if (periode) {
      headers['X-Periode-Id'] = String(periode.id);
    }
    if (poste) {
      headers['X-Poste-Id'] = String(poste.id);
    }
  }

  const requeteModifiee = Object.keys(headers).length > 0 ? req.clone({ setHeaders: headers }) : req;

  return next(requeteModifiee).pipe(
    catchError((erreur: HttpErrorResponse) => {
      if (erreur.status === 401 && token) {
        auth.logout();
        router.navigate(['/login']);
      } else if (erreur.status === 403 && erreur.error?.code === 'mot_de_passe_a_changer') {
        // La coquille ferme alors la modale d'espace de travail (voir TabsPage).
        auth.exigerChangementMotDePasse();
        router.navigate(['/mot-de-passe']);
      }
      return throwError(() => erreur);
    }),
  );
};

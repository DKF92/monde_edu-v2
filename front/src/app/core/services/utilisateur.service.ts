import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  OptionsUtilisateurs,
  Utilisateur,
  UtilisateurAvecMotDePasse,
  UtilisateurSaisie,
} from '../models/utilisateur.model';

export interface FiltresUtilisateurs {
  recherche?: string;
  poste_id?: number | null;
  statut?: 'actif' | 'inactif' | null;
}

/** Administration des utilisateurs de l'etablissement courant. */
@Injectable({ providedIn: 'root' })
export class UtilisateurService {
  private readonly http = inject(HttpClient);
  private readonly url = `${environment.apiUrl}/utilisateurs`;

  lister(filtres: FiltresUtilisateurs = {}): Observable<Utilisateur[]> {
    const params: Record<string, string> = {};
    if (filtres.recherche?.trim()) {
      params['recherche'] = filtres.recherche.trim();
    }
    if (filtres.poste_id) {
      params['poste_id'] = String(filtres.poste_id);
    }
    if (filtres.statut) {
      params['statut'] = filtres.statut;
    }
    return this.http.get<{ data: Utilisateur[] }>(this.url, { params }).pipe(map((r) => r.data));
  }

  /** Postes, niveaux et matieres proposes dans les formulaires. */
  options(): Observable<OptionsUtilisateurs> {
    return this.http.get<OptionsUtilisateurs>(`${this.url}/options`);
  }

  creer(saisie: UtilisateurSaisie): Observable<UtilisateurAvecMotDePasse> {
    return this.http.post<UtilisateurAvecMotDePasse>(this.url, saisie);
  }

  modifier(id: number, saisie: UtilisateurSaisie): Observable<Utilisateur> {
    return this.http.put<{ data: Utilisateur }>(`${this.url}/${id}`, saisie).pipe(map((r) => r.data));
  }

  changerStatut(id: number, statut: 'actif' | 'inactif'): Observable<Utilisateur> {
    return this.http.patch<{ data: Utilisateur }>(`${this.url}/${id}/statut`, { statut }).pipe(map((r) => r.data));
  }

  reinitialiserMotDePasse(id: number): Observable<UtilisateurAvecMotDePasse> {
    return this.http.post<UtilisateurAvecMotDePasse>(`${this.url}/${id}/mot-de-passe`, {});
  }
}

import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  ApercuFrais,
  DetailInscription,
  LotMiseAJourEnLigne,
  OptionsInscription,
  PageInscriptions,
  ResultatEnLigne,
  ResultatMatricule,
  SaisieInscription,
  StatutInscription,
} from '../models/inscription.model';

export interface FiltresInscriptions {
  page: number;
  par_page: number;
  recherche?: string;
  statut?: StatutInscription | null;
  niveau_id?: number | null;
  classe_id?: number | null;
  affecte?: boolean | null;
  redoublant?: boolean | null;
  en_ligne?: boolean | null;
}

/** Inscriptions de l'annee de travail (droit inscriptions.gerer). */
@Injectable({ providedIn: 'root' })
export class InscriptionService {
  private readonly http = inject(HttpClient);
  private readonly url = `${environment.apiUrl}/inscriptions`;

  lister(filtres: FiltresInscriptions): Observable<PageInscriptions> {
    let params = new HttpParams().set('page', filtres.page).set('par_page', filtres.par_page);
    if (filtres.recherche?.trim()) params = params.set('recherche', filtres.recherche.trim());
    if (filtres.statut !== null && filtres.statut !== undefined) params = params.set('statut', filtres.statut);
    if (filtres.niveau_id) params = params.set('niveau_id', filtres.niveau_id);
    if (filtres.classe_id) params = params.set('classe_id', filtres.classe_id);
    if (filtres.affecte !== null && filtres.affecte !== undefined) params = params.set('affecte', filtres.affecte ? 1 : 0);
    if (filtres.redoublant !== null && filtres.redoublant !== undefined) params = params.set('redoublant', filtres.redoublant ? 1 : 0);
    if (filtres.en_ligne !== null && filtres.en_ligne !== undefined) params = params.set('en_ligne', filtres.en_ligne ? 1 : 0);
    return this.http.get<PageInscriptions>(this.url, { params });
  }

  options(): Observable<OptionsInscription> {
    return this.http.get<OptionsInscription>(`${this.url}/options`);
  }

  apercuFrais(niveauId: number, affecte: boolean): Observable<ApercuFrais> {
    return this.http.get<ApercuFrais>(`${this.url}/apercu-frais`, { params: { niveau_id: niveauId, affecte: affecte ? 1 : 0 } });
  }

  rechercherMatricule(matricule: string): Observable<ResultatMatricule> {
    return this.http.get<ResultatMatricule>(`${this.url}/matricule/${encodeURIComponent(matricule)}`);
  }

  /** Recu de preinscription sur le site de l'Etat (peut prendre quelques secondes). */
  enLigne(matricule: string): Observable<ResultatEnLigne> {
    return this.http.get<ResultatEnLigne>(`${this.url}/en-ligne/${encodeURIComponent(matricule)}`);
  }

  detail(id: number): Observable<DetailInscription> {
    return this.http.get<{ data: DetailInscription }>(`${this.url}/${id}`).pipe(map((r) => r.data));
  }

  creer(saisie: SaisieInscription): Observable<DetailInscription> {
    return this.http.post<{ data: DetailInscription }>(this.url, saisie).pipe(map((r) => r.data));
  }

  modifier(id: number, saisie: SaisieInscription): Observable<DetailInscription> {
    return this.http.put<{ data: DetailInscription }>(`${this.url}/${id}`, saisie).pipe(map((r) => r.data));
  }

  changerClasse(id: number, classeId: number): Observable<DetailInscription> {
    return this.http.patch<{ data: DetailInscription }>(`${this.url}/${id}/classe`, { classe_id: classeId }).pipe(map((r) => r.data));
  }

  enregistrerFournitures(id: number, fournitures: { id: number; quantite_remise: number }[]): Observable<DetailInscription> {
    return this.http.patch<{ data: DetailInscription }>(`${this.url}/${id}/fournitures`, { fournitures }).pipe(map((r) => r.data));
  }

  /** Annuler = supprimer l'inscription (la fiche de l'eleve reste). */
  annuler(id: number): Observable<void> {
    return this.http.delete<void>(`${this.url}/${id}`);
  }

  /** Mise a jour en ligne d'un lot d'inscriptions non verifiees (apres l'identifiant donne). */
  miseAJourEnLigne(apresId: number): Observable<LotMiseAJourEnLigne> {
    return this.http.post<LotMiseAJourEnLigne>(`${this.url}/en-ligne/mise-a-jour`, { apres_id: apresId });
  }

  envoyerPhoto(id: number, fichier: File): Observable<DetailInscription> {
    const donnees = new FormData();
    donnees.append('photo', fichier);
    return this.http.post<{ data: DetailInscription }>(`${this.url}/${id}/photo`, donnees).pipe(map((r) => r.data));
  }
}

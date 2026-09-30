import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { DocumentService } from './document.service';
import {
  BilanCaisse,
  DetailPaiement,
  SaisieModification,
  CriteresBilan,
  FiltresBilan,
  ModePaiement,
  PageJournal,
  ReglementCaisse,
  ResultatCaisse,
  ResultatEncaissement,
  SaisieEncaissement,
  SituationCaisse,
} from '../models/caisse.model';

export interface FiltresJournal extends CriteresBilan {
  page: number;
  par_page: number;
  periode?: 'jour' | 'semaine' | 'mois' | 'annee' | 'dates';
  du?: string | null;
  au?: string | null;
  mode?: ModePaiement | null;
  caissier_id?: number | null;
  /** Seulement les encaissements de l'utilisateur connecte. */
  moi?: boolean;
  recherche?: string;
  /** Matricule exact : tous les paiements de l'eleve sur l'annee, quel que soit l'encaisseur. */
  matricule?: string;
  /** Cellule du bilan : type de frais ou dettes, statut de l'eleve. */
  type_frais_id?: number | null;
  dette?: boolean;
  affecte?: boolean | null;
}

/** Caisse (reglements.encaisser) et journal des paiements (reglements.voir). */
@Injectable({ providedIn: 'root' })
export class CaisseService {
  private readonly http = inject(HttpClient);
  private readonly api = environment.apiUrl;
  private readonly documents = inject(DocumentService);

  rechercher(texte: string): Observable<ResultatCaisse[]> {
    return this.http.get<{ data: ResultatCaisse[] }>(`${this.api}/caisse/recherche`, { params: { q: texte } }).pipe(map((r) => r.data));
  }

  situation(inscriptionId: number): Observable<SituationCaisse> {
    return this.http.get<{ data: SituationCaisse }>(`${this.api}/caisse/inscriptions/${inscriptionId}`).pipe(map((r) => r.data));
  }

  encaisser(inscriptionId: number, saisie: SaisieEncaissement): Observable<ResultatEncaissement> {
    return this.http.post<ResultatEncaissement>(`${this.api}/caisse/inscriptions/${inscriptionId}/reglements`, saisie);
  }

  journal(filtres: FiltresJournal): Observable<PageJournal> {
    let params = new HttpParams().set('page', filtres.page).set('par_page', filtres.par_page);
    if (filtres.periode) params = params.set('periode', filtres.periode);
    if (filtres.du) params = params.set('du', filtres.du);
    if (filtres.au) params = params.set('au', filtres.au);
    if (filtres.mode) params = params.set('mode', filtres.mode);
    if (filtres.caissier_id) params = params.set('caissier_id', filtres.caissier_id);
    if (filtres.moi) params = params.set('moi', 1);
    if (filtres.recherche?.trim()) params = params.set('recherche', filtres.recherche.trim());
    if (filtres.matricule?.trim()) params = params.set('matricule', filtres.matricule.trim().toUpperCase());
    if (filtres.type_frais_id) params = params.set('type_frais_id', filtres.type_frais_id);
    if (filtres.dette) params = params.set('dette', 1);
    if (filtres.affecte !== null && filtres.affecte !== undefined) params = params.set('affecte', filtres.affecte ? 1 : 0);
    params = this.parametresCriteres(params, filtres);
    return this.http.get<PageJournal>(`${this.api}/reglements`, { params });
  }

  /** Detail d'un paiement : paiement, inscription, historique des corrections. */
  detail(id: number): Observable<DetailPaiement> {
    return this.http.get<{ data: DetailPaiement }>(`${this.api}/reglements/${id}`).pipe(map((r) => r.data));
  }

  /** Corrige un paiement (motif obligatoire). */
  modifier(id: number, saisie: SaisieModification): Observable<ReglementCaisse> {
    return this.http.put<{ data: ReglementCaisse }>(`${this.api}/reglements/${id}`, saisie).pipe(map((r) => r.data));
  }

  /** Supprime un paiement (motif obligatoire, historise). */
  supprimer(id: number, motif: string): Observable<void> {
    return this.http.delete<void>(`${this.api}/reglements/${id}`, { body: { motif } });
  }

  /** Point des paiements encaisses par l'utilisateur connecte. */
  bilan(filtres: FiltresBilan): Observable<BilanCaisse> {
    return this.http.get<{ data: BilanCaisse }>(`${this.api}/caisse/bilan`, { params: this.parametresBilan(filtres) }).pipe(map((r) => r.data));
  }

  /** Bilan imprimable, affiche dans la visionneuse (PDF, Word, Excel). */
  async ouvrirBilan(filtres: FiltresBilan, titre = 'Bilan de caisse'): Promise<boolean> {
    await this.documents.afficher({ titre, chemin: '/caisse/bilan/pdf', params: this.parametresBilan(filtres), nomFichier: 'bilan-caisse' });
    return true;
  }

  /** Bilan general : general, genre, redoublement, cycle, niveau, classe. */
  async ouvrirBilanGeneral(filtres: FiltresBilan, titre = 'Bilan général'): Promise<boolean> {
    await this.documents.afficher({ titre, chemin: '/caisse/bilan/general/pdf', params: this.parametresBilan(filtres), nomFichier: 'bilan-general' });
    return true;
  }

  /** Recu d'un paiement, affiche dans la visionneuse. */
  async ouvrirRecu(id: number, numero?: string): Promise<boolean> {
    await this.documents.afficher({
      titre: numero ? `Reçu de paiement n° ${numero}` : 'Reçu de paiement',
      chemin: `/reglements/${id}/recu`,
      nomFichier: numero ? `recu-${numero}` : `recu-${id}`,
    });
    return true;
  }

  private parametresBilan(filtres: FiltresBilan): HttpParams {
    let params = new HttpParams().set('periode', filtres.periode);
    if (filtres.du) params = params.set('du', filtres.du);
    if (filtres.au) params = params.set('au', filtres.au);
    if (filtres.mois) params = params.set('mois', filtres.mois);
    return this.parametresCriteres(params, filtres);
  }

  /** Criteres eleve (bilan, bilan PDF, liste d'une cellule). */
  parametresCriteres(params: HttpParams, c: CriteresBilan): HttpParams {
    if (c.sexe) params = params.set('sexe', c.sexe);
    if (c.redoublant !== null && c.redoublant !== undefined) params = params.set('redoublant', c.redoublant ? 1 : 0);
    if (c.cycle) params = params.set('cycle', c.cycle);
    if (c.niveau_id) params = params.set('niveau_id', c.niveau_id);
    if (c.classe_id) params = params.set('classe_id', c.classe_id);
    return params;
  }
}

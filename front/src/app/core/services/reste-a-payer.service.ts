import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { CriteresBilan } from '../models/caisse.model';
import { DocumentService } from './document.service';

/** Une ligne du reste a payer (une inscription de l'annee). */
export interface LigneReste {
  id: number;
  eleve_id: number;
  matricule: string;
  nom: string;
  prenoms: string;
  sexe: 'M' | 'F' | null;
  classe: string | null;
  niveau: string | null;
  affecte: boolean;
  redoublant: boolean;
  /** 0 sans classe, 1 attente 1er versement, 2 attente de solder, 3 solde. */
  statut: number;
  assistance: 'reduction' | 'cas' | null;
  montant_du: number;
  reduction: number;
  a_payer: number;
  paye: number;
  reste_inscription: number;
  dette: number;
  dette_reduite: number;
  dette_apres: number;
  dette_payee: number;
  reste_dette: number;
  reste: number;
  /** Annees des dettes ("2024-2025, 2025-2026"). */
  dette_annees: string | null;
  /** Liste des dettes : 1 a payer, 2 soldee (null sans dette). */
  etat_dette: number | null;
}

export interface TotauxReste {
  montant_du: number;
  reduction: number;
  a_payer: number;
  paye: number;
  reste_inscription: number;
  dette: number;
  dette_reduite: number;
  dette_apres: number;
  dette_payee: number;
  reste_dette: number;
  reste: number;
}

export interface PageReste {
  data: LigneReste[];
  total: number;
  page: number;
  par_page: number;
  annee: string;
  compteurs: Record<number, number>;
  totaux: TotauxReste;
  criteres_libelle: string;
  criteres_disponibles: {
    cycles: { valeur: string; libelle: string }[];
    niveaux: { id: number; libelle: string; cycle: string }[];
    classes: { id: number; libelle: string; niveau_id: number }[];
  };
}

export interface FiltresReste extends CriteresBilan {
  page?: number;
  par_page?: number;
  recherche?: string;
  statut?: number | null;
}

export type ModeReste = 'reste' | 'dettes';

/**
 * Reste a payer des eleves (droit reglements.voir) et liste des dettes
 * (reglements.voir ou dettes.gerer) : memes filtres et documents ; pour les
 * dettes, "statut" = 1 dette a payer, 2 dette soldee.
 */
@Injectable({ providedIn: 'root' })
export class ResteAPayerService {
  private readonly http = inject(HttpClient);
  private readonly documents = inject(DocumentService);
  lister(filtres: FiltresReste, mode: ModeReste = 'reste'): Observable<PageReste> {
    return this.http.get<PageReste>(this.url(mode), { params: this.parametres(filtres) });
  }

  /** Liste imprimable (classe apres classe), avec ou sans statistiques. */
  imprimer(filtres: FiltresReste, statistiques: boolean, titre: string, mode: ModeReste = 'reste'): Promise<void> {
    let params = this.parametres({ ...filtres, page: undefined, par_page: undefined });
    if (statistiques) params = params.set('stats', 1);
    return this.documents.afficher({
      titre,
      chemin: mode === 'dettes' ? '/dettes/document' : '/reste-a-payer/document',
      params,
      nomFichier: (mode === 'dettes' ? 'dettes' : 'reste-a-payer') + (statistiques ? '-statistiques' : ''),
    });
  }

  private url(mode: ModeReste): string {
    return `${environment.apiUrl}/${mode === 'dettes' ? 'dettes' : 'reste-a-payer'}`;
  }

  private parametres(f: FiltresReste): HttpParams {
    let p = new HttpParams();
    if (f.page) p = p.set('page', f.page);
    if (f.par_page) p = p.set('par_page', f.par_page);
    if (f.recherche?.trim()) p = p.set('recherche', f.recherche.trim());
    if (f.statut !== null && f.statut !== undefined) p = p.set('statut', f.statut);
    if (f.sexe) p = p.set('sexe', f.sexe);
    if (f.redoublant !== null && f.redoublant !== undefined) p = p.set('redoublant', f.redoublant ? 1 : 0);
    if (f.cycle) p = p.set('cycle', f.cycle);
    if (f.niveau_id) p = p.set('niveau_id', f.niveau_id);
    if (f.classe_id) p = p.set('classe_id', f.classe_id);
    return p;
  }
}

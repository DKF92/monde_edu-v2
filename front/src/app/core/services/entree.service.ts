import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ModePaiement } from '../models/caisse.model';
import { DocumentService } from './document.service';

/** Listes du formulaire d'entree. */
export interface OptionsEntree {
  banques: { id: number; libelle: string }[];
  /** Personnel habilite aux operations bancaires (versement effectue par). */
  versements: { id: number; nom: string }[];
  categories: { valeur: string; libelle: string }[];
}

/** Entree banque : la banque tient lieu de beneficiaire. */
export interface Entree {
  id: number;
  numero: string;
  date: string;
  categorie: string;
  categorie_libelle: string;
  libelle: string;
  montant: number;
  banque_id: number;
  banque: string | null;
  verse_par_id: number | null;
  verse_par: string | null;
  mode: ModePaiement;
  provenance: string | null;
  piece_comptable: string | null;
  justificatif_url: string | null;
  justificatif_pdf: boolean;
  saisi_par_id: number | null;
  saisi_par: string | null;
  saisie_le: string | null;
}

export interface DetailEntree extends Entree {
  historique: {
    action: 'modification' | 'suppression';
    motif: string;
    avant: Partial<Entree> & { montant: number };
    apres: (Partial<Entree> & { montant: number }) | null;
    date: string;
    par: string;
  }[];
}

/** Entrees caisse : paiements des eleves, total de chaque caissier. */
export interface CaisseEntrees {
  nombre: number;
  montant: number;
  frais: number;
  dettes: number;
  par_caissier: { id: number | null; nom: string; nombre: number; frais: number; dettes: number; montant: number }[];
}

export interface PageEntrees {
  data: Entree[];
  total: number;
  page: number;
  par_page: number;
  du: string | null;
  au: string | null;
  /** Sans le droit "Voir les entrees" : seulement les siennes. */
  mes_entrees: boolean;
  totaux: { nombre: number; montant: number; versements_caisse: number };
  /** null sans le droit "Voir les entrees". */
  caisse: CaisseEntrees | null;
  categories: { valeur: string; libelle: string }[];
  banques: { id: number; libelle: string }[];
  saisies: { id: number; nom: string }[];
}

export interface FiltresEntrees {
  page?: number;
  par_page?: number;
  periode?: 'jour' | 'semaine' | 'mois' | 'annee' | 'dates';
  du?: string | null;
  au?: string | null;
  categorie?: string | null;
  banque_id?: number | null;
  saisi_par_id?: number | null;
  recherche?: string;
}

export interface SaisieEntree {
  banque_id: number;
  verse_par_id: number | null;
  date_entree: string;
  categorie: string;
  libelle: string;
  montant: number;
  mode_paiement: ModePaiement;
  provenance: string | null;
  piece_comptable: string | null;
  justificatif?: File | null;
  retirer_justificatif?: boolean;
  motif?: string;
}

/** Entrees : banque (saisies) et caisse (paiements des eleves, par caissier). */
@Injectable({ providedIn: 'root' })
export class EntreeService {
  private readonly http = inject(HttpClient);
  private readonly documents = inject(DocumentService);
  private readonly url = `${environment.apiUrl}/entrees`;

  lister(filtres: FiltresEntrees): Observable<PageEntrees> {
    return this.http.get<PageEntrees>(this.url, { params: this.parametres(filtres) });
  }

  options(): Observable<OptionsEntree> {
    return this.http.get<OptionsEntree>(`${this.url}/options`);
  }

  detail(id: number): Observable<DetailEntree> {
    return this.http.get<{ data: DetailEntree }>(`${this.url}/${id}`).pipe(map((r) => r.data));
  }

  creer(saisie: SaisieEntree): Observable<Entree> {
    return this.http.post<{ data: Entree }>(this.url, this.formulaire(saisie)).pipe(map((r) => r.data));
  }

  /** POST multipart : un nouveau justificatif peut accompagner la correction. */
  modifier(id: number, saisie: SaisieEntree): Observable<Entree> {
    return this.http.post<{ data: Entree }>(`${this.url}/${id}`, this.formulaire(saisie)).pipe(map((r) => r.data));
  }

  supprimer(id: number, motif: string): Observable<void> {
    return this.http.delete<void>(`${this.url}/${id}`, { body: { motif } });
  }

  imprimerListe(filtres: FiltresEntrees, titre: string): Promise<void> {
    return this.documents.afficher({ titre, chemin: '/entrees/document', params: this.parametres({ ...filtres, page: undefined, par_page: undefined }), nomFichier: 'entrees' });
  }

  private formulaire(s: SaisieEntree): FormData {
    const fd = new FormData();
    fd.append('banque_id', String(s.banque_id));
    if (s.verse_par_id) fd.append('verse_par_id', String(s.verse_par_id));
    fd.append('date_entree', s.date_entree);
    fd.append('categorie', s.categorie);
    fd.append('libelle', s.libelle);
    fd.append('montant', String(s.montant));
    fd.append('mode_paiement', s.mode_paiement);
    if (s.provenance) fd.append('provenance', s.provenance);
    if (s.piece_comptable) fd.append('piece_comptable', s.piece_comptable);
    if (s.justificatif) fd.append('justificatif', s.justificatif);
    if (s.retirer_justificatif) fd.append('retirer_justificatif', '1');
    if (s.motif) fd.append('motif', s.motif);
    return fd;
  }

  private parametres(f: FiltresEntrees): HttpParams {
    let p = new HttpParams();
    if (f.page) p = p.set('page', f.page);
    if (f.par_page) p = p.set('par_page', f.par_page);
    if (f.periode) p = p.set('periode', f.periode);
    if (f.periode === 'dates' && f.du) p = p.set('du', f.du);
    if (f.periode === 'dates' && f.au) p = p.set('au', f.au);
    if (f.categorie) p = p.set('categorie', f.categorie);
    if (f.banque_id) p = p.set('banque_id', f.banque_id);
    if (f.saisi_par_id) p = p.set('saisi_par_id', f.saisi_par_id);
    if (f.recherche?.trim()) p = p.set('recherche', f.recherche.trim());
    return p;
  }
}

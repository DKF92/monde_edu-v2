import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ModePaiement } from '../models/caisse.model';
import { DocumentService } from './document.service';

export interface Depense {
  id: number;
  numero: string;
  date: string;
  categorie: string;
  categorie_libelle: string;
  libelle: string;
  montant: number;
  mode: ModePaiement;
  beneficiaire: string | null;
  piece_comptable: string | null;
  justificatif_url: string | null;
  justificatif_pdf: boolean;
  payeur_id: number | null;
  payeur: string | null;
  saisie_le: string | null;
}

export interface DetailDepense extends Depense {
  historique: {
    action: 'modification' | 'suppression';
    motif: string;
    avant: Partial<Depense> & { montant: number };
    apres: (Partial<Depense> & { montant: number }) | null;
    date: string;
    par: string;
  }[];
}

export interface PageDepenses {
  data: Depense[];
  total: number;
  page: number;
  par_page: number;
  du: string | null;
  au: string | null;
  /** Sans le droit "Voir les depenses" : seulement les siennes. */
  mes_depenses: boolean;
  totaux: { nombre: number; montant: number; par_categorie: { categorie: string; libelle: string; montant: number }[] };
  categories: { valeur: string; libelle: string }[];
  payeurs: { id: number; nom: string }[];
}

export interface FiltresDepenses {
  page?: number;
  par_page?: number;
  periode?: 'jour' | 'semaine' | 'mois' | 'annee' | 'dates';
  du?: string | null;
  au?: string | null;
  categorie?: string | null;
  mode?: string | null;
  payeur_id?: number | null;
  recherche?: string;
}

export interface SaisieDepense {
  date_depense: string;
  categorie: string;
  libelle: string;
  montant: number;
  mode_paiement: ModePaiement;
  beneficiaire: string | null;
  piece_comptable: string | null;
  justificatif?: File | null;
  retirer_justificatif?: boolean;
  motif?: string;
}

export interface PointCaisse {
  periode: { periode: 'jour' | 'dates' | 'mois' | 'annee'; du: string | null; au: string | null; mois: string | null; libelle: string };
  titre: string;
  annee: string;
  mois_disponibles: { valeur: string; libelle: string }[];
  encaissements: number;
  depenses: number;
  solde: number;
  par_categorie: { categorie: string; libelle: string; montant: number }[];
  par_mois: { mois: string; encaissements: number; salaires: number; autres: number; depenses: number; solde: number }[];
}

export interface FiltresPoint {
  periode: 'jour' | 'dates' | 'mois' | 'annee';
  du?: string | null;
  au?: string | null;
  mois?: string | null;
}

/** Depenses (sorties de caisse), point et solde de caisse. */
@Injectable({ providedIn: 'root' })
export class DepenseService {
  private readonly http = inject(HttpClient);
  private readonly documents = inject(DocumentService);
  private readonly url = `${environment.apiUrl}/depenses`;

  lister(filtres: FiltresDepenses): Observable<PageDepenses> {
    return this.http.get<PageDepenses>(this.url, { params: this.parametres(filtres) });
  }

  detail(id: number): Observable<DetailDepense> {
    return this.http.get<{ data: DetailDepense }>(`${this.url}/${id}`).pipe(map((r) => r.data));
  }

  creer(saisie: SaisieDepense): Observable<Depense> {
    return this.http.post<{ data: Depense }>(this.url, this.formulaire(saisie)).pipe(map((r) => r.data));
  }

  /** POST multipart : un nouveau justificatif peut accompagner la correction. */
  modifier(id: number, saisie: SaisieDepense): Observable<Depense> {
    return this.http.post<{ data: Depense }>(`${this.url}/${id}`, this.formulaire(saisie)).pipe(map((r) => r.data));
  }

  supprimer(id: number, motif: string): Observable<void> {
    return this.http.delete<void>(`${this.url}/${id}`, { body: { motif } });
  }

  point(filtres: FiltresPoint): Observable<PointCaisse> {
    return this.http.get<{ data: PointCaisse }>(`${this.url}/point`, { params: this.parametresPoint(filtres) }).pipe(map((r) => r.data));
  }

  imprimerListe(filtres: FiltresDepenses, titre: string): Promise<void> {
    return this.documents.afficher({ titre, chemin: '/depenses/document', params: this.parametres({ ...filtres, page: undefined, par_page: undefined }), nomFichier: 'depenses' });
  }

  imprimerPoint(filtres: FiltresPoint, titre: string): Promise<void> {
    return this.documents.afficher({ titre, chemin: '/depenses/point/document', params: this.parametresPoint(filtres), nomFichier: 'point-de-caisse' });
  }

  private formulaire(s: SaisieDepense): FormData {
    const fd = new FormData();
    fd.append('date_depense', s.date_depense);
    fd.append('categorie', s.categorie);
    fd.append('libelle', s.libelle);
    fd.append('montant', String(s.montant));
    fd.append('mode_paiement', s.mode_paiement);
    if (s.beneficiaire) fd.append('beneficiaire', s.beneficiaire);
    if (s.piece_comptable) fd.append('piece_comptable', s.piece_comptable);
    if (s.justificatif) fd.append('justificatif', s.justificatif);
    if (s.retirer_justificatif) fd.append('retirer_justificatif', '1');
    if (s.motif) fd.append('motif', s.motif);
    return fd;
  }

  private parametres(f: FiltresDepenses): HttpParams {
    let p = new HttpParams();
    if (f.page) p = p.set('page', f.page);
    if (f.par_page) p = p.set('par_page', f.par_page);
    if (f.periode) p = p.set('periode', f.periode);
    if (f.periode === 'dates' && f.du) p = p.set('du', f.du);
    if (f.periode === 'dates' && f.au) p = p.set('au', f.au);
    if (f.categorie) p = p.set('categorie', f.categorie);
    if (f.mode) p = p.set('mode', f.mode);
    if (f.payeur_id) p = p.set('payeur_id', f.payeur_id);
    if (f.recherche?.trim()) p = p.set('recherche', f.recherche.trim());
    return p;
  }

  private parametresPoint(f: FiltresPoint): HttpParams {
    let p = new HttpParams().set('periode', f.periode);
    if (f.du) p = p.set('du', f.du);
    if (f.au) p = p.set('au', f.au);
    if (f.mois) p = p.set('mois', f.mois);
    return p;
  }
}

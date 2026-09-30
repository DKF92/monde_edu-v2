import { Component, computed, inject, signal } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  calendarOutline,
  schoolOutline,
  listOutline,
  printOutline,
  searchOutline,
  cloudOfflineOutline,
  optionsOutline,
  closeOutline,
  documentsOutline,
  maleFemaleOutline,
  repeatOutline,
  layersOutline,
  peopleOutline,
  eyeOutline,
  personOutline,
  personAddOutline,
  alertCircleOutline,
  walletOutline,
} from 'ionicons/icons';
import { ColonnePoint, EleveInscrit, FiltresPoint, LignePoint, ModePoint, Point, PointsService, SectionPoint } from '../../core/services/points.service';
import { CriteresBilan } from '../../core/models/caisse.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE, paginer } from '../../shared/pagination.component';

const TEXTES: Record<ModePoint, { titre: string; description: string; icone: string }> = {
  inscriptions: {
    titre: 'Point des inscriptions',
    description: 'Élèves inscrits sur la période : passés à la caisse, cas sociaux et pas passés à la caisse.',
    icone: 'person-add-outline',
  },
  dettes: {
    titre: 'Point des dettes',
    description: 'Dettes des années précédentes encaissées sur la période et situation des dettes à ce jour.',
    icone: 'alert-circle-outline',
  },
};

/**
 * Point des inscriptions (V1 Point_inscription) et point des dettes (V1
 * Point_dette), presentes comme le point de caisse : memes filtres (periode,
 * criteres eleve), tableaux affectes / non affectes / total, impression du
 * point et impression generale. Le mode vient des donnees de la route.
 */
@Component({
  selector: 'app-point',
  standalone: true,
  imports: [NgTemplateOutlet, IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, SelecteurComponent, PaginationComponent],
  templateUrl: './point.page.html',
  styleUrls: ['../paiements/point-caisse.page.scss', './point.page.scss'],
})
export class PointPage {
  private readonly service = inject(PointsService);
  private readonly toasts = inject(ToastController);
  private readonly route = inject(ActivatedRoute);
  readonly router = inject(Router);

  readonly mode: ModePoint = this.route.snapshot.data['mode'] === 'dettes' ? 'dettes' : 'inscriptions';
  readonly textes = TEXTES[this.mode];
  readonly colonnes: ColonnePoint[] = ['affecte', 'non_affecte', 'total'];

  readonly point = signal<Point | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal<string | null>(null);
  readonly impression = signal<'point' | 'general' | null>(null);
  readonly modaleFiltres = signal(false);

  readonly filtres = signal<FiltresPoint>({ periode: 'annee' });
  readonly du = signal(this.aujourdhui());
  readonly au = signal(this.aujourdhui());
  readonly erreurDates = signal<string | null>(null);

  // Liste des eleves d'une case (point des inscriptions).
  readonly liste = signal<{ titre: string; etat: string | null; affecte: boolean | null; eleves: EleveInscrit[] | null } | null>(null);
  readonly impressionListe = signal(false);
  readonly pageListe = signal(1);
  readonly tailleListe = signal(TAILLES_PAGE[1]);
  readonly elevesPage = computed(() => paginer(this.liste()?.eleves ?? [], this.pageListe(), this.tailleListe()));

  readonly moisDisponibles = computed(() => this.point()?.mois_disponibles ?? []);
  readonly optionsMois = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Choisir un mois…' },
    ...this.moisDisponibles().map((m, i) => ({ valeur: i + 1, libelle: m.libelle })),
  ]);
  readonly rangMois = computed(() => {
    const f = this.filtres();
    return f.periode === 'mois' ? this.moisDisponibles().findIndex((m) => m.valeur === f.mois) + 1 : 0;
  });

  readonly optionsSexe: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Tous' },
    { valeur: 1, libelle: 'Filles' },
    { valeur: 2, libelle: 'Garçons' },
  ];
  readonly optionsRedoublant: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Tous' },
    { valeur: 1, libelle: 'Oui' },
    { valeur: 2, libelle: 'Non' },
  ];
  readonly cycles = computed(() => this.point()?.criteres_disponibles.cycles ?? []);
  readonly optionsCycle = computed<OptionSelecteur[]>(() => [{ valeur: 0, libelle: 'Tous' }, ...this.cycles().map((c, i) => ({ valeur: i + 1, libelle: c.libelle }))]);
  readonly optionsNiveau = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Tous' },
    ...(this.point()?.criteres_disponibles.niveaux ?? []).map((n) => ({ valeur: n.id, libelle: n.libelle })),
  ]);
  readonly optionsClasse = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Toutes' },
    ...(this.point()?.criteres_disponibles.classes ?? []).map((c) => ({ valeur: c.id, libelle: c.libelle })),
  ]);
  readonly optionsCaissier = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Tous les caissiers' },
    ...(this.point()?.caissiers ?? []).map((c) => ({ valeur: c.id, libelle: c.nom })),
  ]);

  readonly valeurSexe = computed(() => (this.filtres().sexe === 'F' ? 1 : this.filtres().sexe === 'M' ? 2 : 0));
  readonly valeurRedoublant = computed(() => (this.filtres().redoublant === true ? 1 : this.filtres().redoublant === false ? 2 : 0));
  readonly valeurCycle = computed(() => this.cycles().findIndex((c) => c.valeur === this.filtres().cycle) + 1);
  readonly nombreCriteres = computed(() => {
    const f = this.filtres();
    return [f.sexe, f.redoublant ?? null, f.cycle, f.niveau_id, f.classe_id, f.caissier_id].filter((v) => v !== null && v !== undefined && v !== '').length;
  });

  constructor() {
    addIcons({
      calendarOutline, schoolOutline, listOutline, printOutline, searchOutline, cloudOfflineOutline, optionsOutline, closeOutline, documentsOutline,
      maleFemaleOutline, repeatOutline, layersOutline, peopleOutline, eyeOutline, personOutline, personAddOutline, alertCircleOutline, walletOutline,
    });
  }

  ionViewWillEnter(): void {
    this.charger();
  }

  charger(filtres = this.filtres()): void {
    this.chargement.set(true);
    this.erreur.set(null);
    this.service.point(this.mode, filtres).subscribe({
      next: (p) => {
        this.filtres.set(filtres);
        this.point.set(p);
        this.chargement.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.chargement.set(false);
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        const message = (erreurs && Object.values(erreurs)[0]?.[0]) || e.error?.message || 'Impossible de charger le point.';
        if (erreurs) this.erreurDates.set(message);
        else this.erreur.set(message);
      },
    });
  }

  // ------------------------------------------------ Periodes

  afficherDates(): void {
    this.erreurDates.set(null);
    if (!this.du()) {
      this.erreurDates.set('Saisissez la date de début.');
      return;
    }
    if (this.au() && this.au() < this.du()) {
      this.erreurDates.set('La date de fin doit suivre la date de début.');
      return;
    }
    this.charger({ ...this.criteres(), periode: 'dates', du: this.du(), au: this.au() || this.du() });
  }

  choisirMois(rang: number): void {
    const mois = this.moisDisponibles()[rang - 1];
    if (mois) {
      this.erreurDates.set(null);
      this.charger({ ...this.criteres(), periode: 'mois', mois: mois.valeur });
    }
  }

  anneeScolaire(): void {
    this.erreurDates.set(null);
    this.charger({ ...this.criteres(), periode: 'annee' });
  }

  aujourdhuiPoint(): void {
    this.erreurDates.set(null);
    this.du.set(this.aujourdhui());
    this.au.set(this.aujourdhui());
    this.charger({ ...this.criteres(), periode: 'jour' });
  }

  // ------------------------------------------------ Criteres

  choisirSexe(v: number): void {
    this.majCriteres({ sexe: v === 1 ? 'F' : v === 2 ? 'M' : null });
  }

  choisirRedoublant(v: number): void {
    this.majCriteres({ redoublant: v === 1 ? true : v === 2 ? false : null });
  }

  choisirCycle(rang: number): void {
    this.majCriteres({ cycle: this.cycles()[rang - 1]?.valeur ?? null, niveau_id: null, classe_id: null });
  }

  choisirNiveau(id: number): void {
    this.majCriteres({ niveau_id: id || null, cycle: null, classe_id: null });
  }

  choisirClasse(id: number): void {
    this.majCriteres({ classe_id: id || null, cycle: null, niveau_id: null });
  }

  choisirCaissier(id: number): void {
    this.charger({ ...this.filtres(), caissier_id: id || null });
  }

  effacerCriteres(): void {
    this.charger({ ...this.filtres(), sexe: null, redoublant: null, cycle: null, niveau_id: null, classe_id: null, caissier_id: null });
  }

  private majCriteres(changement: CriteresBilan): void {
    this.charger({ ...this.filtres(), ...changement });
  }

  private criteres(): FiltresPoint {
    const { sexe, redoublant, cycle, niveau_id, classe_id, caissier_id } = this.filtres();
    return { periode: this.filtres().periode, sexe, redoublant, cycle, niveau_id, classe_id, caissier_id };
  }

  // ------------------------------------------------ Actions

  /** Oeil d'une case : eleves (inscriptions) ou paiements de dettes (dettes). */
  ouvrir(s: SectionPoint, l: LignePoint | null, c: ColonnePoint): void {
    const p = this.point();
    if (!p) return;
    const affecte = c === 'total' ? null : c === 'affecte';
    if (this.mode === 'dettes') {
      if (s.cle !== 'encaisse' && !s.cle.startsWith('caissier-')) {
        this.router.navigateByUrl('/tabs/paiements/dettes');
        return;
      }
      const params: Record<string, string | number> = { periode: p.periode.periode === 'annee' ? 'annee' : 'dates', dette: 1, titre: p.titre, cellule: 'Dettes' + (affecte === null ? '' : affecte ? ' · élèves affectés' : ' · élèves non affectés') };
      if (p.periode.du) params['du'] = p.periode.du;
      if (p.periode.au) params['au'] = p.periode.au;
      if (affecte !== null) params['affecte'] = affecte ? 1 : 0;
      const f = this.filtres();
      if (f.caissier_id) params['caissier_id'] = f.caissier_id;
      if (f.sexe) params['sexe'] = f.sexe;
      if (f.redoublant !== null && f.redoublant !== undefined) params['redoublant'] = f.redoublant ? 1 : 0;
      if (f.cycle) params['cycle'] = f.cycle;
      if (f.niveau_id) params['niveau_id'] = f.niveau_id;
      if (f.classe_id) params['classe_id'] = f.classe_id;
      this.router.navigate(['/tabs/paiements/liste'], { queryParams: params });
      return;
    }
    this.pageListe.set(1);
    const etat = l ? l.cle : null;
    const filtres = { ...this.filtres(), ...(s.niveau_id ? { niveau_id: s.niveau_id, cycle: null, classe_id: null } : {}) };
    this.liste.set({ titre: '…', etat, affecte, eleves: null });
    this.listeFiltres = filtres;
    this.service.eleves(filtres, etat, affecte).subscribe({
      next: (r) => this.liste.set({ titre: r.titre, etat, affecte, eleves: r.data }),
      error: () => {
        this.liste.set(null);
        this.notifier('Impossible de charger la liste des élèves.');
      },
    });
  }

  private listeFiltres: FiltresPoint = this.filtres();

  /** Liste de tous les inscrits du point affiche (bouton au-dessus du tableau). */
  listeInscrits(): void {
    const s = this.point()?.sections[0];
    if (s) this.ouvrir(s, null, 'total');
  }

  async imprimerListe(): Promise<void> {
    const l = this.liste();
    if (!l) return;
    this.impressionListe.set(true);
    await this.service.imprimerEleves(this.listeFiltres, l.etat, l.affecte, l.titre);
    this.impressionListe.set(false);
  }

  ouvrirEleve(e: EleveInscrit): void {
    this.liste.set(null);
    this.router.navigate(['/tabs/eleves', e.eleve_id]);
  }

  async imprimer(general = false): Promise<void> {
    const p = this.point();
    if (!p) return;
    this.impression.set(general ? 'general' : 'point');
    await this.service.imprimer(this.mode, this.filtres(), (general ? 'Point général · ' : '') + p.titre, general);
    this.impression.set(null);
  }

  // ------------------------------------------------ Affichage

  v(p: Point, l: LignePoint, c: ColonnePoint): string {
    const n = l[c].toLocaleString('fr-FR');
    return (l.unite ?? p.unite) === 'montant' ? n + ' F' : n;
  }

  f(v: number): string {
    return v.toLocaleString('fr-FR') + ' F';
  }

  /** L'oeil n'a de sens que pour les lignes d'eleves ou d'encaissements. */
  oeil(s: SectionPoint, l: LignePoint | null): boolean {
    if (this.mode === 'inscriptions') return true;
    if (s.cle === 'encaisse' || s.cle.startsWith('caissier-')) return l === null;
    return l === null;
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private aujourdhui(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  private async notifier(message: string): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: 'danger' });
    await toast.present();
  }
}

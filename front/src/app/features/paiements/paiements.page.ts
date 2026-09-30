import { Component, OnDestroy, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  cashOutline,
  searchOutline,
  printOutline,
  walletOutline,
  phonePortraitOutline,
  documentTextOutline,
  swapHorizontalOutline,
  receiptOutline,
  calendarOutline,
  personOutline,
  cloudOfflineOutline,
  eyeOutline,
  lockClosedOutline,
  createOutline,
  trashOutline,
  chevronBackOutline,
  closeCircleOutline,
  funnelOutline,
} from 'ionicons/icons';
import { CaisseService } from '../../core/services/caisse.service';
import { ActionsCaisseService } from '../../core/services/actions-caisse.service';
import { AuthService } from '../../core/services/auth.service';
import { CriteresBilan, LIBELLES_MODE, ModePaiement, PageJournal, ReglementCaisse } from '../../core/models/caisse.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE } from '../../shared/pagination.component';
import { ListeProgressive } from '../../shared/liste-progressive';

type Periode = 'jour' | 'semaine' | 'mois' | 'annee' | 'dates';

/**
 * Paiements : journal de caisse de l'annee (periode, mode, caissier,
 * recherche), totaux par mode, recu PDF ; bouton Encaisser pour la caisse.
 */
@Component({
  selector: 'app-paiements',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent, SelecteurComponent, PaginationComponent],
  templateUrl: './paiements.page.html',
  styleUrl: './paiements.page.scss',
})
export class PaiementsPage implements OnDestroy {
  private readonly caisse = inject(CaisseService);
  private readonly auth = inject(AuthService);
  private readonly toasts = inject(ToastController);
  private readonly route = inject(ActivatedRoute);
  private readonly actions = inject(ActionsCaisseService);
  readonly router = inject(Router);

  readonly libellesMode = LIBELLES_MODE;
  readonly peutEncaisser = computed(() => this.auth.aPermission('reglements.encaisser'));
  readonly peutModifier = computed(() => this.auth.aPermission('reglements.modifier'));
  /** Voir tous les paiements ; sinon (droit Encaisser seul) : ses encaissements uniquement. */
  readonly voitTout = computed(() => this.auth.aPermission('reglements.voir'));
  readonly peutVoir = computed(() => this.voitTout() || this.peutEncaisser());

  /** Arrivee depuis la Caisse (ses encaissements). */
  readonly moi = signal(false);
  /** Recherche par matricule : tous les paiements de l'eleve, quel que soit l'encaisseur. */
  readonly matricule = signal('');
  /** Texte du champ : la recherche ne part qu'au clic sur la loupe (ou Entree). */
  readonly saisieMatricule = signal('');
  /**
   * Droit Encaisser : seulement ses encaissements (sauf recherche par
   * matricule). Droit Voir seul : tous les paiements.
   */
  readonly mesEncaissements = computed(() => this.peutEncaisser() && !this.matricule().trim());
  /** Cellule du bilan : type de frais / dettes et statut de l'eleve. */
  readonly cellule = signal<{ type_frais_id: number | null; dette: boolean; affecte: boolean | null; libelle: string } | null>(null);
  /** Titre du bilan d'origine (ex. "Bilan de septembre 2026"). */
  readonly titreBilan = signal<string | null>(null);
  readonly totalParts = signal<number | null>(null);
  /** Criteres eleve du bilan d'origine (genre, redoublant, cycle / niveau / classe). */
  readonly criteresBilan = signal<CriteresBilan>({});

  readonly liste = new ListeProgressive<ReglementCaisse>(50);
  readonly totaux = signal<PageJournal['totaux'] | null>(null);
  readonly caissiers = signal<PageJournal['caissiers']>([]);

  readonly recherche = signal('');
  readonly periode = signal<Periode>('jour');
  readonly du = signal(this.jour(0));
  readonly au = signal(this.jour(0));
  readonly mode = signal<ModePaiement | ''>('');
  readonly caissier = signal(0);
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);

  readonly lignesPage = computed(() => this.liste.page(this.page(), this.taille()));
  readonly pageEnAttente = computed(() => this.liste.pageEnAttente(this.page(), this.taille()));

  readonly periodes: { valeur: Periode; libelle: string }[] = [
    { valeur: 'jour', libelle: 'Aujourd\'hui' },
    { valeur: 'semaine', libelle: '7 jours' },
    { valeur: 'mois', libelle: 'Ce mois' },
    { valeur: 'annee', libelle: 'Toute l\'année' },
    { valeur: 'dates', libelle: 'Dates…' },
  ];

  private readonly listeModes = Object.keys(LIBELLES_MODE) as ModePaiement[];
  /** Selecteur (valeurs numeriques) : 0 = tous, sinon rang du mode + 1. */
  readonly rangMode = computed(() => (this.mode() ? this.listeModes.indexOf(this.mode() as ModePaiement) + 1 : 0));

  readonly optionsMode = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Tous les modes' },
    ...this.listeModes.map((m, i) => ({ valeur: i + 1, libelle: LIBELLES_MODE[m] })),
  ]);

  readonly optionsCaissier = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Tous les caissiers' },
    ...this.caissiers().map((c) => ({ valeur: c.id, libelle: c.nom })),
  ]);

  readonly cartesMode: { mode: ModePaiement; icone: string; teinte: string }[] = [
    { mode: 'especes', icone: 'wallet-outline', teinte: 'vert' },
    { mode: 'mobile_money', icone: 'phone-portrait-outline', teinte: 'orange' },
    { mode: 'cheque', icone: 'document-text-outline', teinte: 'violet' },
    { mode: 'virement', icone: 'swap-horizontal-outline', teinte: 'bleu' },
  ];

  /** Titre du tableau : suit les filtres. */
  readonly titre = computed(() => {
    const n = this.liste.total();
    const morceaux = [`${n} paiement${n > 1 ? 's' : ''}`];
    const p = this.periode();
    if (p === 'jour') morceaux.push('aujourd\'hui');
    else if (p === 'semaine') morceaux.push('sur 7 jours');
    else if (p === 'mois') morceaux.push('ce mois');
    else if (p === 'dates') morceaux.push(this.du() === this.au() ? `le ${this.dateFr(this.du())}` : `du ${this.dateFr(this.du())} au ${this.dateFr(this.au())}`);
    if (this.mode()) morceaux.push(this.libellesMode[this.mode() as ModePaiement].toLowerCase());
    const c = this.caissiers().find((x) => x.id === this.caissier());
    if (c) morceaux.push(`par ${c.nom}`);
    if (this.matricule().trim()) {
      return `${n} paiement${n > 1 ? 's' : ''} · élève ${this.matricule().trim().toUpperCase()} · toute l'année · tous encaisseurs`;
    }
    if (this.cellule()) morceaux.push(this.cellule()!.libelle);
    if (this.mesEncaissements()) morceaux.push('mes encaissements');
    if (this.recherche().trim()) morceaux.push(`pour « ${this.recherche().trim()} »`);
    return morceaux.join(' · ');
  });

  private minuterie?: ReturnType<typeof setTimeout>;

  constructor() {
    addIcons({
      cashOutline,
      searchOutline,
      printOutline,
      walletOutline,
      phonePortraitOutline,
      documentTextOutline,
      swapHorizontalOutline,
      receiptOutline,
      calendarOutline,
      personOutline,
      cloudOfflineOutline,
      eyeOutline,
      lockClosedOutline,
      createOutline,
      trashOutline,
      chevronBackOutline,
      closeCircleOutline,
      funnelOutline,
    });
  }

  /**
   * Retour sur la page : journal a jour sans F5. Arrivee depuis la Caisse :
   * periode, "mes encaissements" et cellule du bilan en parametres.
   */
  ionViewWillEnter(): void {
    const p = this.route.snapshot.queryParamMap;
    if (p.keys.length) {
      const periode = p.get('periode') as Periode | null;
      if (periode) this.periode.set(periode);
      if (p.get('du')) this.du.set(p.get('du')!);
      if (p.get('au')) this.au.set(p.get('au')!);
      this.moi.set(p.get('moi') === '1');
      this.titreBilan.set(p.get('titre'));
      const avecCellule = p.has('type_frais_id') || p.has('dette') || p.has('affecte');
      this.criteresBilan.set({
        sexe: (p.get('sexe') as 'M' | 'F' | null) ?? null,
        redoublant: p.has('redoublant') ? p.get('redoublant') === '1' : null,
        cycle: p.get('cycle'),
        niveau_id: Number(p.get('niveau_id')) || null,
        classe_id: Number(p.get('classe_id')) || null,
      });
      this.cellule.set(avecCellule ? {
        type_frais_id: Number(p.get('type_frais_id')) || null,
        dette: p.get('dette') === '1',
        affecte: p.has('affecte') ? p.get('affecte') === '1' : null,
        libelle: p.get('cellule') ?? '',
      } : null);
      this.mode.set('');
      // Point des dettes : caissier choisi dans ses filtres.
      this.caissier.set(Number(p.get('caissier_id')) || 0);
      this.recherche.set('');
      this.matricule.set('');
      this.saisieMatricule.set('');
      this.page.set(1);
    }
    if (this.peutVoir()) {
      this.charger();
    }
  }

  /** Retire le filtre de la cellule du bilan (garde la periode). */
  retirerCellule(): void {
    this.cellule.set(null);
    this.router.navigate([], {
      queryParams: { type_frais_id: null, dette: null, affecte: null, cellule: null },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
    this.relancer();
  }

  ionViewWillLeave(): void {
    this.liste.arreter();
  }

  ngOnDestroy(): void {
    this.liste.arreter();
    clearTimeout(this.minuterie);
  }

  charger(evenement?: CustomEvent): void {
    this.liste.charger(
      (page, parPage) =>
        this.caisse.journal({
          page,
          par_page: parPage,
          periode: this.periode(),
          du: this.periode() === 'dates' ? this.du() : null,
          au: this.periode() === 'dates' ? this.au() : null,
          mode: this.mode() || null,
          caissier_id: this.caissier() || null,
          moi: this.moi(),
          recherche: this.recherche(),
          matricule: this.matricule(),
          type_frais_id: this.cellule()?.type_frais_id ?? null,
          dette: this.cellule()?.dette ?? false,
          affecte: this.cellule()?.affecte ?? null,
          ...this.criteresBilan(),
        }),
      (r) => {
        this.totaux.set(r.totaux);
        this.totalParts.set(r.total_parts);
        // Dates de la periode vues par le serveur (reprises si on passe a « Dates… »).
        if (r.du) this.du.set(r.du);
        if (r.au) this.au.set(r.au);
        this.caissiers.set(r.caissiers);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
    );
  }

  choisirPeriode(p: Periode): void {
    this.periode.set(p);
    this.titreBilan.set(null);
    this.criteresBilan.set({});
    const aujourdhui = this.jour(0);
    if (p === 'jour') this.du.set(aujourdhui);
    if (p === 'semaine') this.du.set(this.jour(-6));
    if (p === 'mois') this.du.set(aujourdhui.slice(0, 8) + '01');
    if (p !== 'dates') this.au.set(aujourdhui);
    this.relancer();
  }

  majDate(borne: 'du' | 'au', valeur: string): void {
    if (!valeur) return;
    this[borne].set(valeur);
    this.relancer();
  }

  /** Loupe (ou Entree) : lance la recherche du matricule saisi. */
  chercherMatricule(): void {
    const texte = this.saisieMatricule().trim().toUpperCase();
    this.saisieMatricule.set(texte);
    this.matricule.set(texte);
    this.page.set(1);
    this.liste.arreter();
    clearTimeout(this.minuterie);
    this.charger();
  }

  effacerMatricule(): void {
    const actif = !!this.matricule();
    this.saisieMatricule.set('');
    if (actif) this.chercherMatricule();
  }

  rechercher(texte: string): void {
    this.recherche.set(texte);
    this.page.set(1);
    this.liste.arreter();
    clearTimeout(this.minuterie);
    this.minuterie = setTimeout(() => this.charger(), 250);
  }

  choisirMode(m: ModePaiement | ''): void {
    this.mode.set(m);
    this.relancer();
  }

  choisirRangMode(rang: number): void {
    this.choisirMode(rang ? this.listeModes[rang - 1] : '');
  }

  choisirCaissier(id: number): void {
    this.caissier.set(id);
    this.relancer();
  }

  changerTaille(taille: number): void {
    this.taille.set(taille);
    this.page.set(1);
  }

  async imprimer(r: ReglementCaisse, evenement: Event): Promise<void> {
    evenement.stopPropagation();
    if (!(await this.caisse.ouvrirRecu(r.id, r.numero_recu))) {
      const toast = await this.toasts.create({ message: 'Impossible d\'ouvrir le reçu. Autorisez les fenêtres (pop-up) puis réessayez.', duration: 3500, color: 'danger' });
      await toast.present();
    }
  }

  /** Detail du paiement (consultation, sans formulaire). */
  ouvrir(r: ReglementCaisse): void {
    this.router.navigate(['/tabs/paiements', r.id]);
  }

  /** Encaisser : matricule demande a chaque fois. */
  encaisser(): void {
    this.actions.encaisser();
  }

  /** Motif obligatoire puis formulaire de modification. */
  async modifier(r: ReglementCaisse, evenement: Event): Promise<void> {
    evenement.stopPropagation();
    const motif = await this.actions.motif('modifier', r.numero_recu);
    if (motif) {
      this.router.navigate(['/tabs/paiements', r.id, 'modifier'], { state: { motif } });
    }
  }

  /** Motif obligatoire puis suppression immediate. */
  async supprimer(r: ReglementCaisse, evenement: Event): Promise<void> {
    evenement.stopPropagation();
    const motif = await this.actions.motif('supprimer', r.numero_recu);
    if (!motif) {
      return;
    }
    this.caisse.supprimer(r.id, motif).subscribe({
      next: async () => {
        const toast = await this.toasts.create({ message: `Paiement n° ${r.numero_recu} supprimé.`, duration: 3000, color: 'dark' });
        await toast.present();
        this.charger();
      },
      error: async (e) => {
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        const toast = await this.toasts.create({ message: (erreurs && Object.values(erreurs)[0]?.[0]) || e.error?.message || 'Suppression impossible.', duration: 3500, color: 'danger' });
        await toast.present();
      },
    });
  }

  f(v: number | null | undefined): string {
    return (v ?? 0).toLocaleString('fr-FR') + ' F';
  }

  dateFr(date: string | null | undefined): string {
    return date ? date.slice(0, 10).split('-').reverse().join('/') : '—';
  }

  heure(date: string): string {
    return date.slice(11, 16);
  }

  versement(n: number | null): string {
    return n ? (n === 1 ? '1er versement' : `${n}e versement`) : 'Dette';
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private relancer(): void {
    this.page.set(1);
    this.charger();
  }

  private jour(decalage: number): string {
    const d = new Date();
    d.setDate(d.getDate() + decalage);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
}

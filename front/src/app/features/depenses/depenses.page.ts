import { Component, OnDestroy, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  addOutline,
  searchOutline,
  printOutline,
  statsChartOutline,
  receiptOutline,
  peopleOutline,
  pricetagOutline,
  personOutline,
  cloudOfflineOutline,
  eyeOutline,
  createOutline,
  trashOutline,
  closeOutline,
  documentAttachOutline,
  timeOutline,
  walletOutline,
  briefcaseOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { ActionsCaisseService } from '../../core/services/actions-caisse.service';
import { DetailDepense, Depense, DepenseService, FiltresDepenses, PageDepenses } from '../../core/services/depense.service';
import { LIBELLES_MODE } from '../../core/models/caisse.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE } from '../../shared/pagination.component';
import { ListeProgressive } from '../../shared/liste-progressive';

type Periode = 'jour' | 'semaine' | 'mois' | 'annee' | 'dates';

/**
 * Depenses de l'annee (V1 "Liste des depenses") : periode, recherche,
 * categorie, saisie par ; totaux ; detail (justificatif, historique) ;
 * nouvelle depense, modification / suppression avec motif ; impression.
 * Sans "Voir les depenses", on ne voit que les siennes.
 */
@Component({
  selector: 'app-depenses',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent, SelecteurComponent, PaginationComponent],
  templateUrl: './depenses.page.html',
  styleUrl: './depenses.page.scss',
})
export class DepensesPage implements OnDestroy {
  private readonly service = inject(DepenseService);
  private readonly auth = inject(AuthService);
  private readonly actions = inject(ActionsCaisseService);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly libellesMode = LIBELLES_MODE;
  readonly peutSaisir = computed(() => this.auth.aPermission('depenses.gerer'));
  readonly peutVoirTout = computed(() => this.auth.aPermission('depenses.voir'));
  readonly peutModifier = computed(() => this.auth.aPermission('depenses.modifier'));

  readonly liste = new ListeProgressive<Depense>(50);
  readonly infos = signal<Omit<PageDepenses, 'data' | 'total' | 'page' | 'par_page'> | null>(null);

  readonly periode = signal<Periode>('annee');
  readonly du = signal(this.jour(0));
  readonly au = signal(this.jour(0));
  readonly recherche = signal('');
  readonly categorie = signal('');
  readonly payeur = signal(0);
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);
  readonly impression = signal(false);

  /** Detail (modale). */
  readonly detail = signal<DetailDepense | null>(null);
  readonly modaleDetail = signal(false);

  readonly lignesPage = computed(() => this.liste.page(this.page(), this.taille()));
  readonly pageEnAttente = computed(() => this.liste.pageEnAttente(this.page(), this.taille()));

  readonly periodes: { valeur: Periode; libelle: string }[] = [
    { valeur: 'jour', libelle: "Aujourd'hui" },
    { valeur: 'semaine', libelle: '7 jours' },
    { valeur: 'mois', libelle: 'Ce mois' },
    { valeur: 'annee', libelle: "Toute l'année" },
    { valeur: 'dates', libelle: 'Dates…' },
  ];

  readonly categories = computed(() => this.infos()?.categories ?? []);
  readonly optionsCategorie = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Toutes les catégories' },
    ...this.categories().map((c, i) => ({ valeur: i + 1, libelle: c.libelle })),
  ]);
  readonly rangCategorie = computed(() => this.categories().findIndex((c) => c.valeur === this.categorie()) + 1);
  readonly optionsPayeur = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Toutes les saisies' },
    ...(this.infos()?.payeurs ?? []).map((p) => ({ valeur: p.id, libelle: p.nom })),
  ]);

  /** Cartes : total, salaires, autres (V1 "Point salaires / autres depenses"). */
  readonly cartes = computed(() => {
    const t = this.infos()?.totaux;
    const salaires = t?.par_categorie.find((c) => c.categorie === 'salaires')?.montant ?? 0;
    return { total: t?.montant ?? 0, nombre: t?.nombre ?? 0, salaires, autres: (t?.montant ?? 0) - salaires };
  });

  readonly titre = computed(() => {
    const n = this.liste.total();
    const m = [`${n} dépense${n > 1 ? 's' : ''}`];
    const p = this.periode();
    if (p === 'jour') m.push("aujourd'hui");
    else if (p === 'semaine') m.push('sur 7 jours');
    else if (p === 'mois') m.push('ce mois');
    else if (p === 'dates') m.push(this.du() === this.au() ? `le ${this.dateFr(this.du())}` : `du ${this.dateFr(this.du())} au ${this.dateFr(this.au())}`);
    const c = this.categories().find((x) => x.valeur === this.categorie());
    if (c) m.push(c.libelle.toLowerCase());
    const pay = this.infos()?.payeurs.find((x) => x.id === this.payeur());
    if (pay) m.push(`saisies par ${pay.nom}`);
    if (this.infos()?.mes_depenses) m.push('mes dépenses');
    if (this.recherche().trim()) m.push(`pour « ${this.recherche().trim()} »`);
    return m.join(' · ');
  });

  private minuterie?: ReturnType<typeof setTimeout>;

  constructor() {
    addIcons({
      addOutline,
      searchOutline,
      printOutline,
      statsChartOutline,
      receiptOutline,
      peopleOutline,
      pricetagOutline,
      personOutline,
      cloudOfflineOutline,
      eyeOutline,
      createOutline,
      trashOutline,
      closeOutline,
      documentAttachOutline,
      timeOutline,
      walletOutline,
      briefcaseOutline,
    });
  }

  /** Retour sur la page (apres une saisie) : liste a jour sans F5. */
  ionViewWillEnter(): void {
    this.charger();
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
      (page, parPage) => this.service.lister({ ...this.filtres(), page, par_page: parPage }),
      (r) => {
        const { data: _d, total: _t, page: _p, par_page: _pp, ...infos } = r;
        this.infos.set(infos);
        if (r.du) this.du.set(r.du);
        if (r.au) this.au.set(r.au);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
    );
  }

  choisirPeriode(p: Periode): void {
    this.periode.set(p);
    this.relancer();
  }

  majDate(borne: 'du' | 'au', valeur: string): void {
    if (valeur) {
      this[borne].set(valeur);
      this.relancer();
    }
  }

  rechercher(texte: string): void {
    this.recherche.set(texte);
    this.page.set(1);
    this.liste.arreter();
    clearTimeout(this.minuterie);
    this.minuterie = setTimeout(() => this.charger(), 250);
  }

  choisirCategorie(rang: number): void {
    this.categorie.set(this.categories()[rang - 1]?.valeur ?? '');
    this.relancer();
  }

  choisirPayeur(id: number): void {
    this.payeur.set(id);
    this.relancer();
  }

  changerTaille(taille: number): void {
    this.taille.set(taille);
    this.page.set(1);
  }

  async imprimer(): Promise<void> {
    this.impression.set(true);
    await this.service.imprimerListe(this.filtres(), 'Dépenses · ' + this.titre());
    this.impression.set(false);
  }

  // ------------------------------------------------ Detail, modification, suppression

  ouvrir(d: Depense): void {
    this.detail.set(null);
    this.modaleDetail.set(true);
    this.service.detail(d.id).subscribe({
      next: (x) => this.detail.set(x),
      error: () => {
        this.modaleDetail.set(false);
        this.notifier('Impossible de charger la dépense.', true);
      },
    });
  }

  async modifier(d: Depense, evenement?: Event): Promise<void> {
    evenement?.stopPropagation();
    const motif = await this.actions.motif('modifier', d.numero, 'dépense');
    if (motif) {
      this.modaleDetail.set(false);
      this.router.navigate(['/tabs/depenses', d.id, 'modifier'], { state: { motif } });
    }
  }

  async supprimer(d: Depense, evenement?: Event): Promise<void> {
    evenement?.stopPropagation();
    const motif = await this.actions.motif('supprimer', d.numero, 'dépense');
    if (!motif) {
      return;
    }
    this.service.supprimer(d.id, motif).subscribe({
      next: () => {
        this.modaleDetail.set(false);
        this.notifier(`Dépense n° ${d.numero} supprimée.`);
        this.charger();
      },
      error: (e: HttpErrorResponse) => {
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        this.notifier((erreurs && Object.values(erreurs)[0]?.[0]) || e.error?.message || 'Suppression impossible.', true);
      },
    });
  }

  // ------------------------------------------------ Affichage

  f(v: number | null | undefined): string {
    return (v ?? 0).toLocaleString('fr-FR') + ' F';
  }

  dateFr(date: string | null | undefined): string {
    return date ? date.slice(0, 10).split('-').reverse().join('/') : '—';
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private filtres(): FiltresDepenses {
    return {
      periode: this.periode(),
      du: this.du(),
      au: this.au(),
      categorie: this.categorie() || null,
      payeur_id: this.payeur() || null,
      recherche: this.recherche(),
    };
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

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}

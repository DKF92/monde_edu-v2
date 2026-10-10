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
  businessOutline,
  personOutline,
  pricetagOutline,
  cloudOfflineOutline,
  eyeOutline,
  createOutline,
  trashOutline,
  closeOutline,
  documentAttachOutline,
  timeOutline,
  walletOutline,
  cashOutline,
  swapHorizontalOutline,
  listOutline,
  enterOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { ActionsCaisseService } from '../../core/services/actions-caisse.service';
import { CaisseEntrees, DetailEntree, Entree, EntreeService, FiltresEntrees, PageEntrees } from '../../core/services/entree.service';
import { LIBELLES_MODE } from '../../core/models/caisse.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE } from '../../shared/pagination.component';
import { ListeProgressive } from '../../shared/liste-progressive';

type Periode = 'jour' | 'semaine' | 'mois' | 'annee' | 'dates';

/**
 * Entrees de l'annee, pendant des depenses :
 * - caisse = paiements des eleves : pas de liste (elle est dans Paiements),
 *   seulement le total de chaque caissier sur la periode ;
 * - banque = entrees saisies (versements, subventions, dons...), la banque
 *   tenant lieu de beneficiaire : liste, detail, correction / suppression
 *   avec motif, impression.
 */
@Component({
  selector: 'app-entrees',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent, SelecteurComponent, PaginationComponent],
  templateUrl: './entrees.page.html',
  styleUrls: ['../depenses/depenses.page.scss', './entrees.page.scss'],
})
export class EntreesPage implements OnDestroy {
  private readonly service = inject(EntreeService);
  private readonly auth = inject(AuthService);
  private readonly actions = inject(ActionsCaisseService);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly libellesMode = LIBELLES_MODE;
  readonly peutSaisir = computed(() => this.auth.aPermission('entrees.gerer'));
  readonly peutVoirTout = computed(() => this.auth.aPermission('entrees.voir'));
  readonly peutModifier = computed(() => this.auth.aPermission('entrees.modifier'));
  readonly peutVoirPaiements = computed(() => this.auth.aPermission('reglements.voir'));

  readonly liste = new ListeProgressive<Entree>(50);
  readonly infos = signal<Omit<PageEntrees, 'data' | 'total' | 'page' | 'par_page'> | null>(null);
  readonly caisse = computed<CaisseEntrees | null>(() => this.infos()?.caisse ?? null);

  readonly periode = signal<Periode>('annee');
  readonly du = signal(this.jour());
  readonly au = signal(this.jour());
  readonly recherche = signal('');
  readonly categorie = signal('');
  readonly banque = signal(0);
  readonly saisie = signal(0);
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);
  readonly impression = signal(false);

  readonly detail = signal<DetailEntree | null>(null);
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
  readonly optionsBanque = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Toutes les banques' },
    ...(this.infos()?.banques ?? []).map((b) => ({ valeur: b.id, libelle: b.libelle })),
  ]);
  readonly optionsSaisie = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Toutes les saisies' },
    ...(this.infos()?.saisies ?? []).map((p) => ({ valeur: p.id, libelle: p.nom })),
  ]);

  /** Total des entrees : caisse + banque, sans les versements de la caisse (deja encaisses). */
  readonly cartes = computed(() => {
    const t = this.infos()?.totaux;
    const caisse = this.caisse()?.montant ?? 0;
    const banque = t?.montant ?? 0;
    const versements = t?.versements_caisse ?? 0;
    return { caisse, banque, versements, nombre: t?.nombre ?? 0, total: caisse + banque - versements };
  });

  readonly libellePeriode = computed(() => {
    const p = this.periode();
    if (p === 'jour') return "aujourd'hui";
    if (p === 'semaine') return 'sur 7 jours';
    if (p === 'mois') return 'ce mois';
    if (p === 'dates') return this.du() === this.au() ? `le ${this.dateFr(this.du())}` : `du ${this.dateFr(this.du())} au ${this.dateFr(this.au())}`;
    return "de l'année";
  });

  readonly titre = computed(() => {
    const n = this.liste.total();
    const m = [`${n} entrée${n > 1 ? 's' : ''} banque`, this.libellePeriode()];
    const c = this.categories().find((x) => x.valeur === this.categorie());
    if (c) m.push(c.libelle.toLowerCase());
    const b = this.infos()?.banques.find((x) => x.id === this.banque());
    if (b) m.push(b.libelle);
    const s = this.infos()?.saisies.find((x) => x.id === this.saisie());
    if (s) m.push(`saisies par ${s.nom}`);
    if (this.infos()?.mes_entrees) m.push('mes entrées');
    if (this.recherche().trim()) m.push(`pour « ${this.recherche().trim()} »`);
    return m.join(' · ');
  });

  private minuterie?: ReturnType<typeof setTimeout>;

  constructor() {
    addIcons({
      addOutline, searchOutline, printOutline, statsChartOutline, businessOutline, personOutline, pricetagOutline, cloudOfflineOutline, eyeOutline,
      createOutline, trashOutline, closeOutline, documentAttachOutline, timeOutline, walletOutline, cashOutline, swapHorizontalOutline, listOutline, enterOutline,
    });
  }

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

  choisirBanque(id: number): void {
    this.banque.set(id);
    this.relancer();
  }

  choisirSaisie(id: number): void {
    this.saisie.set(id);
    this.relancer();
  }

  changerTaille(taille: number): void {
    this.taille.set(taille);
    this.page.set(1);
  }

  /** Paiements d'un caissier sur la periode (liste des paiements). */
  voirPaiements(caissierId: number | null): void {
    const params: Record<string, string | number> = { periode: this.periode() === 'annee' ? 'annee' : 'dates' };
    if (this.periode() !== 'annee' && this.infos()?.du) params['du'] = this.infos()!.du!;
    if (this.periode() !== 'annee' && this.infos()?.au) params['au'] = this.infos()!.au!;
    if (caissierId) params['caissier_id'] = caissierId;
    this.router.navigate(['/tabs/paiements/liste'], { queryParams: params });
  }

  async imprimer(): Promise<void> {
    this.impression.set(true);
    await this.service.imprimerListe(this.filtres(), 'Entrées · ' + this.libellePeriode());
    this.impression.set(false);
  }

  // ------------------------------------------------ Detail, modification, suppression

  ouvrir(e: Entree): void {
    this.detail.set(null);
    this.modaleDetail.set(true);
    this.service.detail(e.id).subscribe({
      next: (x) => this.detail.set(x),
      error: () => {
        this.modaleDetail.set(false);
        this.notifier("Impossible de charger l'entrée.", true);
      },
    });
  }

  async modifier(e: Entree, evenement?: Event): Promise<void> {
    evenement?.stopPropagation();
    const motif = await this.actions.motif('modifier', e.numero, 'entrée');
    if (motif) {
      this.modaleDetail.set(false);
      this.router.navigate(['/tabs/entrees', e.id, 'modifier'], { state: { motif } });
    }
  }

  async supprimer(e: Entree, evenement?: Event): Promise<void> {
    evenement?.stopPropagation();
    const motif = await this.actions.motif('supprimer', e.numero, 'entrée');
    if (!motif) return;
    this.service.supprimer(e.id, motif).subscribe({
      next: () => {
        this.modaleDetail.set(false);
        this.notifier(`Entrée n° ${e.numero} supprimée.`);
        this.charger();
      },
      error: (err: HttpErrorResponse) => {
        const erreurs = err.error?.errors as Record<string, string[]> | undefined;
        this.notifier((erreurs && Object.values(erreurs)[0]?.[0]) || err.error?.message || 'Suppression impossible.', true);
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

  private filtres(): FiltresEntrees {
    return {
      periode: this.periode(),
      du: this.du(),
      au: this.au(),
      categorie: this.categorie() || null,
      banque_id: this.banque() || null,
      saisi_par_id: this.saisie() || null,
      recherche: this.recherche(),
    };
  }

  private relancer(): void {
    this.page.set(1);
    this.charger();
  }

  private jour(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}

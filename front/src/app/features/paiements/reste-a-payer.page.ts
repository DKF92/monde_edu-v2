import { Component, OnDestroy, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  searchOutline,
  optionsOutline,
  printOutline,
  documentsOutline,
  peopleOutline,
  schoolOutline,
  hourglassOutline,
  timeOutline,
  checkmarkDoneCircleOutline,
  cloudOfflineOutline,
  closeOutline,
  maleFemaleOutline,
  repeatOutline,
  layersOutline,
  walletOutline,
} from 'ionicons/icons';
import { CriteresBilan } from '../../core/models/caisse.model';
import { AuthService } from '../../core/services/auth.service';
import { FiltresReste, LigneReste, ModeReste, PageReste, ResteAPayerService } from '../../core/services/reste-a-payer.service';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE } from '../../shared/pagination.component';
import { ListeProgressive } from '../../shared/liste-progressive';

/**
 * Reste a payer (V1 "Point scolarite") : tous les eleves inscrits de l'annee,
 * par nom et prenoms, compteurs par etape de paiement, recherche, filtres
 * eleve (genre, redoublant, cycle / niveau / classe) ; listes imprimables par
 * classe, avec ou sans statistiques.
 *
 * Meme page pour la liste des dettes (route data mode = 'dettes', V1
 * liste_dette) : eleves inscrits de l'annee qui ont une dette, compteurs
 * dette a payer / soldee.
 */
@Component({
  selector: 'app-reste-a-payer',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent, SelecteurComponent, PaginationComponent],
  templateUrl: './reste-a-payer.page.html',
  styleUrl: './reste-a-payer.page.scss',
})
export class ResteAPayerPage implements OnDestroy {
  private readonly service = inject(ResteAPayerService);
  readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  readonly mode: ModeReste = inject(ActivatedRoute).snapshot.data['mode'] === 'dettes' ? 'dettes' : 'reste';
  readonly dettes = this.mode === 'dettes';

  readonly liste = new ListeProgressive<LigneReste>(50);
  readonly infos = signal<Omit<PageReste, 'data' | 'total' | 'page' | 'par_page'> | null>(null);

  readonly recherche = signal('');
  readonly statut = signal<number | null>(null);
  readonly criteres = signal<CriteresBilan>({});
  readonly modaleFiltres = signal(false);
  readonly impression = signal<'liste' | 'stats' | null>(null);
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);

  readonly lignesPage = computed(() => this.liste.page(this.page(), this.taille()));
  readonly pageEnAttente = computed(() => this.liste.pageEnAttente(this.page(), this.taille()));

  readonly cartes: { statut: number; libelle: string; icone: string; teinte: string }[] = this.dettes
    ? [
        { statut: 1, libelle: 'Dette à payer', icone: 'time-outline', teinte: 'orange' },
        { statut: 2, libelle: 'Dette soldée', icone: 'checkmark-done-circle-outline', teinte: 'vert' },
      ]
    : [
        { statut: 1, libelle: 'En attente du 1er versement', icone: 'hourglass-outline', teinte: 'violet' },
        { statut: 2, libelle: 'En attente de solder', icone: 'time-outline', teinte: 'bleu' },
        { statut: 3, libelle: 'Soldés', icone: 'checkmark-done-circle-outline', teinte: 'vert' },
      ];
  readonly compteurs = computed<Record<number, number>>(() => this.infos()?.compteurs ?? {});
  readonly totalEleves = computed(() => this.cartes.reduce((s, c) => s + (this.compteurs()[c.statut] ?? 0), 0));

  // ------------------------------------------------ Filtres eleve
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
  readonly cycles = computed(() => this.infos()?.criteres_disponibles.cycles ?? []);
  readonly optionsCycle = computed<OptionSelecteur[]>(() => [{ valeur: 0, libelle: 'Tous' }, ...this.cycles().map((c, i) => ({ valeur: i + 1, libelle: c.libelle }))]);
  readonly optionsNiveau = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Tous' },
    ...(this.infos()?.criteres_disponibles.niveaux ?? []).map((n) => ({ valeur: n.id, libelle: n.libelle })),
  ]);
  readonly optionsClasse = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Toutes' },
    ...(this.infos()?.criteres_disponibles.classes ?? []).map((c) => ({ valeur: c.id, libelle: c.libelle })),
  ]);
  readonly valeurSexe = computed(() => (this.criteres().sexe === 'F' ? 1 : this.criteres().sexe === 'M' ? 2 : 0));
  readonly valeurRedoublant = computed(() => (this.criteres().redoublant === true ? 1 : this.criteres().redoublant === false ? 2 : 0));
  readonly valeurCycle = computed(() => this.cycles().findIndex((c) => c.valeur === this.criteres().cycle) + 1);
  readonly nombreCriteres = computed(() => {
    const c = this.criteres();
    return [c.sexe, c.redoublant ?? null, c.cycle, c.niveau_id, c.classe_id].filter((v) => v !== null && v !== undefined && v !== '').length;
  });

  readonly filtresActifs = computed(() => !!this.recherche().trim() || this.statut() !== null || this.nombreCriteres() > 0);

  /** Titre du tableau : suit les filtres. */
  readonly titre = computed(() => {
    const n = this.liste.total();
    const morceaux = [`${n} élève${n > 1 ? 's' : ''}`];
    const carte = this.cartes.find((c) => c.statut === this.statut());
    if (carte) morceaux.push(carte.libelle.toLowerCase());
    if (this.infos()?.criteres_libelle) morceaux.push(this.infos()!.criteres_libelle);
    if (this.recherche().trim()) morceaux.push(`pour « ${this.recherche().trim()} »`);
    return morceaux.join(' · ');
  });

  private minuterie?: ReturnType<typeof setTimeout>;

  constructor() {
    addIcons({
      searchOutline,
      optionsOutline,
      printOutline,
      documentsOutline,
      peopleOutline,
      schoolOutline,
      hourglassOutline,
      timeOutline,
      checkmarkDoneCircleOutline,
      cloudOfflineOutline,
      closeOutline,
      maleFemaleOutline,
      repeatOutline,
      layersOutline,
      walletOutline,
    });
  }

  /** Retour sur la page (apres un encaissement) : donnees a jour sans F5. */
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
      (page, parPage) => this.service.lister({ ...this.filtres(), page, par_page: parPage }, this.mode),
      (r) => {
        const { data: _d, total: _t, page: _p, par_page: _pp, ...infos } = r;
        this.infos.set(infos);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
    );
  }

  rechercher(texte: string): void {
    this.recherche.set(texte);
    this.page.set(1);
    this.liste.arreter();
    clearTimeout(this.minuterie);
    this.minuterie = setTimeout(() => this.charger(), 250);
  }

  basculerStatut(statut: number | null): void {
    this.statut.set(this.statut() === statut ? null : statut);
    this.relancer();
  }

  // ------------------------------------------------ Criteres (cycle, niveau et classe s'excluent)

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

  effacerCriteres(): void {
    this.criteres.set({});
    this.relancer();
  }

  reinitialiser(): void {
    this.recherche.set('');
    this.statut.set(null);
    this.criteres.set({});
    this.relancer();
  }

  // ------------------------------------------------ Impression

  async imprimer(statistiques: boolean): Promise<void> {
    this.impression.set(statistiques ? 'stats' : 'liste');
    const annee = this.infos()?.annee ?? '';
    const titre = `${this.dettes ? 'Liste des dettes' : 'Reste à payer'} ${annee}${statistiques ? ' · statistiques' : ''}`;
    await this.service.imprimer(this.filtres(), statistiques, titre, this.mode);
    this.impression.set(null);
  }

  changerTaille(taille: number): void {
    this.taille.set(taille);
    this.page.set(1);
  }

  // ------------------------------------------------ Affichage

  f(v: number): string {
    return v.toLocaleString('fr-FR') + ' F';
  }

  initiales(l: LigneReste): string {
    return ((l.nom?.charAt(0) ?? '') + (l.prenoms?.charAt(0) ?? '')).toUpperCase();
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private majCriteres(changement: CriteresBilan): void {
    this.criteres.set({ ...this.criteres(), ...changement });
    this.relancer();
  }

  private filtres(): FiltresReste {
    return { ...this.criteres(), recherche: this.recherche(), statut: this.statut() };
  }

  private relancer(): void {
    this.page.set(1);
    this.charger();
  }

  /**
   * Ligne : fiche d'inscription de l'annee (frais, paiements, encaisser) ;
   * sans le droit Inscriptions (caissier), dossier de l'eleve.
   */
  ouvrir(l: LigneReste): void {
    if (this.auth.aPermission('inscriptions.gerer')) {
      this.router.navigate(['/tabs/inscriptions', l.id]);
    } else {
      this.router.navigate(['/tabs/eleves', l.eleve_id]);
    }
  }
}

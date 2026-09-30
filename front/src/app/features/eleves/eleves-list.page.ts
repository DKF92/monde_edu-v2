import { Component, DestroyRef, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  searchOutline,
  peopleOutline,
  checkmarkCircleOutline,
  closeCircleOutline,
  cloudOfflineOutline,
  folderOpenOutline,
  cloudUploadOutline,
  documentAttachOutline,
  closeOutline,
  informationCircleOutline,
  maleFemaleOutline,
  downloadOutline,
} from 'ionicons/icons';
import { EleveService } from '../../core/services/eleve.service';
import { AuthService } from '../../core/services/auth.service';
import { LigneEleve, PageEleves } from '../../core/models/eleve.model';
import { LIBELLES_STATUT, TONS_STATUT } from '../../core/models/inscription.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE } from '../../shared/pagination.component';
import { ListeProgressive } from '../../shared/liste-progressive';

type FiltreInscription = 'inscrits' | 'non_inscrits' | null;

/**
 * Tous les eleves connus de l'etablissement, inscrits ou non pour l'annee de
 * travail. Chargement progressif (1re page immediate, suite en arriere-plan),
 * recherche serveur qui annule ce chargement. Chaque ligne ouvre le dossier.
 */
@Component({
  selector: 'app-eleves-list',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent, SelecteurComponent, PaginationComponent],
  templateUrl: './eleves-list.page.html',
  styleUrl: './eleves-list.page.scss',
})
export class ElevesListPage implements OnInit, OnDestroy {
  private readonly service = inject(EleveService);
  private readonly auth = inject(AuthService);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);
  readonly router = inject(Router);

  readonly libellesStatut = LIBELLES_STATUT;
  readonly liste = new ListeProgressive<LigneEleve>(50);
  readonly compteurs = signal<PageEleves['compteurs']>({ total: 0, inscrits: 0, non_inscrits: 0 });
  readonly annee = signal<string | null>(null);

  readonly recherche = signal('');
  readonly inscription = signal<FiltreInscription>(null);
  /** 0 = tous, 1 = garcons, 2 = filles. */
  readonly sexe = signal(0);
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);

  readonly importOuvert = signal(false);
  readonly fichierImport = signal<File | null>(null);
  readonly peutImporter = computed(() => this.auth.aPermission('eleves.gerer') || this.auth.aPermission('inscriptions.gerer'));

  readonly lignesPage = computed(() => this.liste.page(this.page(), this.taille()));
  readonly pageEnAttente = computed(() => this.liste.pageEnAttente(this.page(), this.taille()));

  readonly optionsSexe: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Filles et garçons' },
    { valeur: 1, libelle: 'Garçons' },
    { valeur: 2, libelle: 'Filles' },
  ];

  readonly filtresActifs = computed(() => !!this.recherche().trim() || !!this.inscription() || !!this.sexe());

  /** Titre du tableau : suit les filtres. */
  readonly titre = computed(() => {
    const total = this.liste.total();
    const sexe = this.sexe() === 1 ? (total > 1 ? 'garçons' : 'garçon') : this.sexe() === 2 ? (total > 1 ? 'filles' : 'fille') : total > 1 ? 'élèves' : 'élève';
    const morceaux = [`${total} ${sexe}`];
    if (this.inscription() === 'inscrits') morceaux.push(`inscrits en ${this.annee() ?? 'cours'}`);
    if (this.inscription() === 'non_inscrits') morceaux.push(`non inscrits en ${this.annee() ?? 'cours'}`);
    if (this.recherche().trim()) morceaux.push(`pour « ${this.recherche().trim()} »`);
    return morceaux.join(' · ');
  });

  private minuterie?: ReturnType<typeof setTimeout>;
  private premierAffichage = true;

  constructor() {
    addIcons({
      searchOutline,
      peopleOutline,
      checkmarkCircleOutline,
      closeCircleOutline,
      cloudOfflineOutline,
      folderOpenOutline,
      cloudUploadOutline,
      documentAttachOutline,
      closeOutline,
      informationCircleOutline,
      maleFemaleOutline,
      downloadOutline,
    });
  }

  ngOnInit(): void {
    // Recherche de la barre du haut (bureau) : ?recherche=...
    this.route.queryParamMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((params) => {
      this.recherche.set(params.get('recherche') ?? '');
      this.page.set(1);
      this.charger();
    });
  }

  /** Retour sur la page : liste a jour sans F5. */
  ionViewWillEnter(): void {
    if (this.premierAffichage) {
      this.premierAffichage = false;
      return;
    }
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
      (page, parPage) =>
        this.service.lister({
          page,
          par_page: parPage,
          recherche: this.recherche(),
          inscription: this.inscription(),
          sexe: this.sexe() === 1 ? 'M' : this.sexe() === 2 ? 'F' : null,
        }),
      (r) => {
        this.compteurs.set(r.compteurs);
        this.annee.set(r.annee);
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

  filtrerInscription(filtre: FiltreInscription): void {
    this.inscription.set(this.inscription() === filtre ? null : filtre);
    this.page.set(1);
    this.charger();
  }

  filtrerSexe(valeur: number): void {
    this.sexe.set(valeur);
    this.page.set(1);
    this.charger();
  }

  changerTaille(taille: number): void {
    this.taille.set(taille);
    this.page.set(1);
  }

  reinitialiserFiltres(): void {
    this.recherche.set('');
    this.inscription.set(null);
    this.sexe.set(0);
    this.page.set(1);
    this.charger();
  }

  ouvrirDossier(e: LigneEleve): void {
    this.router.navigate(['/tabs/eleves', e.id]);
  }

  // ------------------------------------------------ Import (a venir)

  choisirFichier(evenement: Event): void {
    this.fichierImport.set((evenement.target as HTMLInputElement).files?.[0] ?? null);
  }

  fermerImport(): void {
    this.importOuvert.set(false);
    this.fichierImport.set(null);
  }

  // ------------------------------------------------ Affichage

  initiales(e: LigneEleve): string {
    return ((e.nom?.charAt(0) ?? '') + (e.prenoms?.charAt(0) ?? '')).toUpperCase() || '?';
  }

  dateFr(date: string | null): string {
    return date ? date.slice(0, 10).split('-').reverse().join('/') : '—';
  }

  ton(statut: number): string {
    return TONS_STATUT[statut] ?? '';
  }

  taille_fichier(octets: number): string {
    return octets > 1024 * 1024 ? (octets / 1024 / 1024).toFixed(1) + ' Mo' : Math.ceil(octets / 1024) + ' Ko';
  }
}

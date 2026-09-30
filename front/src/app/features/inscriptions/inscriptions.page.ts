import { Component, OnDestroy, computed, effect, inject, signal, untracked } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  addOutline,
  searchOutline,
  eyeOutline,
  createOutline,
  peopleOutline,
  personAddOutline,
  schoolOutline,
  hourglassOutline,
  checkmarkCircleOutline,
  checkmarkDoneCircleOutline,
  cloudOfflineOutline,
  layersOutline,
  globeOutline,
  ribbonOutline,
  repeatOutline,
  closeCircleOutline,
  syncOutline,
  stopCircleOutline,
  closeOutline,
  alertCircleOutline,
  chevronDownOutline,
  chevronUpOutline,
} from 'ionicons/icons';
import { InscriptionService } from '../../core/services/inscription.service';
import { MiseAJourEnLigneService } from '../../core/services/mise-a-jour-en-ligne.service';
import {
  LIBELLES_STATUT,
  LigneInscription,
  OptionsInscription,
  PageInscriptions,
  StatutInscription,
  TONS_STATUT,
} from '../../core/models/inscription.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE } from '../../shared/pagination.component';
import { ListeProgressive } from '../../shared/liste-progressive';

/**
 * Inscriptions de l'annee de travail : compteurs par etape (V1 inscr_termine),
 * recherche, filtres niveau / affectation / redoublant.
 * Chargement progressif : la 1re page s'affiche tout de suite, le reste arrive
 * en arriere-plan ; une recherche annule ce chargement et interroge l'API.
 */
@Component({
  selector: 'app-inscriptions',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent, SelecteurComponent, PaginationComponent],
  templateUrl: './inscriptions.page.html',
  styleUrl: './inscriptions.page.scss',
})
export class InscriptionsPage implements OnDestroy {
  private readonly service = inject(InscriptionService);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);
  readonly maj = inject(MiseAJourEnLigneService);

  readonly libellesStatut = LIBELLES_STATUT;
  readonly tons = TONS_STATUT;
  /** Inscriptions de l'annee jamais verifiees en ligne. */
  readonly sansEnLigne = signal(0);
  /** Bilan de la mise a jour en ligne deplie. */
  readonly bilanDeplie = signal(false);
  /** Inscription en cours d'annulation. */
  readonly annulation = signal<number | null>(null);
  readonly liste = new ListeProgressive<LigneInscription>(50);

  readonly options = signal<OptionsInscription | null>(null);
  readonly compteurs = signal<PageInscriptions['compteurs']>({ 0: 0, 1: 0, 2: 0, 3: 0 });

  readonly recherche = signal('');
  readonly statut = signal<StatutInscription | null>(null);
  readonly niveau = signal(0);
  /** 0 = tous, 1 = affectes, 2 = non affectes. */
  readonly affectation = signal(0);
  /** 0 = tous, 1 = redoublants, 2 = non redoublants. */
  readonly redoublement = signal(0);
  /** 0 = tous, 1 = inscrits en ligne, 2 = pas inscrits en ligne. */
  readonly enLigne = signal(0);
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);

  readonly lignesPage = computed(() => this.liste.page(this.page(), this.taille()));
  readonly pageEnAttente = computed(() => this.liste.pageEnAttente(this.page(), this.taille()));

  readonly totalActives = computed(() => {
    const c = this.compteurs();
    return (c[0] ?? 0) + (c[1] ?? 0) + (c[2] ?? 0) + (c[3] ?? 0);
  });

  readonly cartes: { statut: StatutInscription; icone: string; teinte: string }[] = [
    { statut: 0, icone: 'school-outline', teinte: 'orange' },
    { statut: 1, icone: 'hourglass-outline', teinte: 'violet' },
    { statut: 2, icone: 'checkmark-circle-outline', teinte: 'bleu' },
    { statut: 3, icone: 'checkmark-done-circle-outline', teinte: 'vert' },
  ];

  readonly optionsNiveaux = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Tous les niveaux' },
    ...(this.options()?.niveaux ?? []).map((n) => ({ valeur: n.id, libelle: n.libelle })),
  ]);

  readonly optionsAffectation: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Affectés et non affectés' },
    { valeur: 1, libelle: 'Affectés' },
    { valeur: 2, libelle: 'Non affectés' },
  ];

  readonly optionsRedoublement: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Redoublants et non redoublants' },
    { valeur: 1, libelle: 'Redoublants' },
    { valeur: 2, libelle: 'Non redoublants' },
  ];

  readonly optionsEnLigne: OptionSelecteur[] = [
    { valeur: 0, libelle: 'En ligne : oui et non' },
    { valeur: 1, libelle: 'Inscrits en ligne' },
    { valeur: 2, libelle: 'Pas inscrits en ligne' },
  ];

  readonly filtresActifs = computed(() => !!this.recherche().trim() || this.statut() !== null || !!this.niveau() || !!this.affectation() || !!this.redoublement() || !!this.enLigne());

  /** Titre du tableau : suit les filtres choisis. */
  readonly titre = computed(() => {
    const total = this.liste.total();
    const morceaux = [`${total} inscription${total > 1 ? 's' : ''}`];
    const niveau = this.options()?.niveaux.find((n) => n.id === this.niveau());
    if (niveau) morceaux.push(`en ${niveau.libelle}`);
    if (this.affectation()) morceaux.push(this.affectation() === 1 ? 'affectés' : 'non affectés');
    if (this.redoublement()) morceaux.push(this.redoublement() === 1 ? 'redoublants' : 'non redoublants');
    if (this.enLigne()) morceaux.push(this.enLigne() === 1 ? 'inscrits en ligne' : 'pas inscrits en ligne');
    if (this.statut() !== null) morceaux.push(this.libellesStatut[this.statut()!].toLowerCase());
    if (this.recherche().trim()) morceaux.push(`pour « ${this.recherche().trim()} »`);
    return morceaux.join(' · ');
  });

  private minuterie?: ReturnType<typeof setTimeout>;

  constructor() {
    addIcons({
      addOutline,
      searchOutline,
      eyeOutline,
      createOutline,
      peopleOutline,
      personAddOutline,
      schoolOutline,
      hourglassOutline,
      checkmarkCircleOutline,
      checkmarkDoneCircleOutline,
      cloudOfflineOutline,
      layersOutline,
      globeOutline,
      ribbonOutline,
      repeatOutline,
      closeCircleOutline,
      syncOutline,
      stopCircleOutline,
      closeOutline,
      alertCircleOutline,
      chevronDownOutline,
      chevronUpOutline,
    });

    // Fin de la mise a jour en ligne : la liste reprend les nouvelles donnees.
    effect(() => {
      if (this.maj.termine() && this.maj.version() > 0) {
        untracked(() => this.charger());
      }
    });
  }

  /** Ionic garde la page en memoire : on recharge a chaque retour (apres une inscription...). */
  ionViewWillEnter(): void {
    this.service.options().subscribe({ next: (o) => this.options.set(o) });
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
          statut: this.statut(),
          niveau_id: this.niveau(),
          affecte: this.affectation() ? this.affectation() === 1 : null,
          redoublant: this.redoublement() ? this.redoublement() === 1 : null,
          en_ligne: this.enLigne() ? this.enLigne() === 1 : null,
        }),
      (r) => {
        this.compteurs.set(r.compteurs);
        this.sansEnLigne.set(r.sans_en_ligne);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
    );
  }

  rechercher(texte: string): void {
    this.recherche.set(texte);
    this.page.set(1);
    // Annule tout de suite le chargement de fond, puis interroge l'API.
    this.liste.arreter();
    clearTimeout(this.minuterie);
    this.minuterie = setTimeout(() => this.charger(), 250);
  }

  filtrer(action: () => void): void {
    action();
    this.page.set(1);
    this.charger();
  }

  appliquer(filtre: 'niveau' | 'affectation' | 'redoublement' | 'enLigne', valeur: number): void {
    this.filtrer(() => this[filtre].set(valeur));
  }

  basculerStatut(statut: StatutInscription | null): void {
    this.filtrer(() => this.statut.set(this.statut() === statut ? null : statut));
  }

  changerTaille(taille: number): void {
    this.taille.set(taille);
    this.page.set(1);
  }

  reinitialiserFiltres(): void {
    this.filtrer(() => {
      this.recherche.set('');
      this.niveau.set(0);
      this.affectation.set(0);
      this.redoublement.set(0);
      this.enLigne.set(0);
      this.statut.set(null);
    });
  }

  ouvrir(l: LigneInscription): void {
    this.router.navigate(['/tabs/inscriptions', l.id]);
  }

  modifier(l: LigneInscription, evenement: Event): void {
    evenement.stopPropagation();
    this.router.navigate(['/tabs/inscriptions', l.id, 'modifier']);
  }

  initiales(l: LigneInscription): string {
    return ((l.eleve.nom?.charAt(0) ?? '') + (l.eleve.prenoms?.charAt(0) ?? '')).toUpperCase() || '?';
  }

  /** Annuler = supprimer l'inscription (V1) ; la fiche de l'eleve reste. */
  async annuler(l: LigneInscription, evenement: Event): Promise<void> {
    evenement.stopPropagation();
    const alerte = await this.alertes.create({
      header: 'Annuler cette inscription ?',
      message: `L'inscription de ${l.eleve.nom} ${l.eleve.prenoms} sera supprimée avec ses frais. Sa fiche est conservée : vous pourrez la reprendre avec « Nouvelle inscription ».`,
      cssClass: 'alerte-me',
      buttons: [
        { text: 'Retour', role: 'cancel' },
        { text: 'Annuler l\'inscription', role: 'confirm', cssClass: 'bouton-danger' },
      ],
    });
    await alerte.present();
    if ((await alerte.onDidDismiss()).role !== 'confirm') {
      return;
    }
    this.annulation.set(l.id);
    this.service.annuler(l.id).subscribe({
      next: () => {
        this.annulation.set(null);
        this.notifier(`Inscription de ${l.eleve.nom} ${l.eleve.prenoms} annulée.`);
        this.charger();
      },
      error: (e: HttpErrorResponse) => {
        this.annulation.set(null);
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        this.notifier((erreurs && Object.values(erreurs)[0]?.[0]) || e.error?.message || 'Annulation impossible.', true);
      },
    });
  }

  /** "Mettre à jour" : verification en ligne de toutes les inscriptions non verifiees. */
  async mettreAJourEnLigne(): Promise<void> {
    const n = this.sansEnLigne();
    const alerte = await this.alertes.create({
      header: 'Mettre à jour depuis l\'inscription en ligne ?',
      message: `${n} inscription${n > 1 ? 's n\'ont' : ' n\'a'} pas été vérifiée${n > 1 ? 's' : ''} sur le site de l'État. Pour chaque reçu trouvé, la fiche est mise à jour directement (identité, contact, statut affecté, provenance, photo). Le traitement continue en arrière-plan.`,
      cssClass: 'alerte-me',
      buttons: [
        { text: 'Retour', role: 'cancel' },
        { text: 'Mettre à jour', role: 'confirm' },
      ],
    });
    await alerte.present();
    if ((await alerte.onDidDismiss()).role === 'confirm') {
      this.bilanDeplie.set(false);
      this.maj.demarrer(n);
    }
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }

  montant(valeur: number): string {
    return valeur.toLocaleString('fr-FR') + ' F';
  }
}

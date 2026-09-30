import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, ToastController, IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  pricetagOutline,
  alertCircleOutline,
  personAddOutline,
  printOutline,
  documentsOutline,
  optionsOutline,
  closeOutline,
  searchOutline,
  trashOutline,
  eyeOutline,
  cloudOfflineOutline,
  peopleOutline,
  walletOutline,
  heartOutline,
  maleFemaleOutline,
  repeatOutline,
  layersOutline,
  schoolOutline,
  calendarOutline,
  arrowForwardOutline,
} from 'ionicons/icons';
import { CibleReduction, FiltresReductions, PageReductions, ReductionAccordee, ReductionsService } from '../../core/services/reductions.service';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE, paginer } from '../../shared/pagination.component';

/**
 * Menu Reductions (V1 liste_reduction, liste_cas, Dette/traitement_dette) :
 * liste des reductions de l'annee, filtres, impression de la liste et
 * impression generale (point par type, genre, niveau, classe) ; les boutons
 * "Reduction dette" et "Reduction inscription" demandent le matricule puis
 * ouvrent le formulaire de reduction.
 */
@Component({
  selector: 'app-reductions',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, SelecteurComponent, PaginationComponent],
  templateUrl: './reductions.page.html',
  styleUrl: './reductions.page.scss',
})
export class ReductionsPage {
  private readonly service = inject(ReductionsService);
  private readonly toasts = inject(ToastController);
  private readonly alertes = inject(AlertController);
  readonly router = inject(Router);

  readonly donnees = signal<PageReductions | null>(null);
  readonly chargement = signal(false);
  readonly erreur = signal(false);
  readonly filtres = signal<FiltresReductions>({});
  readonly recherche = signal('');
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[1]);
  readonly impression = signal<'liste' | 'general' | null>(null);
  readonly suppression = signal<string | null>(null);
  readonly modaleFiltres = signal(false);

  // Fenetre "matricule" (reduction dette / inscription).
  readonly cible = signal<CibleReduction | null>(null);
  readonly matricule = signal('');
  readonly verification = signal(false);
  readonly erreurMatricule = signal<string | null>(null);

  // Saisie des dates des filtres.
  readonly du = signal('');
  readonly au = signal('');

  readonly lignesPage = computed(() => paginer(this.donnees()?.data ?? [], this.page(), this.taille()));

  readonly optionsCible: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Inscriptions et dettes' },
    { valeur: 1, libelle: 'Réductions d\'inscription' },
    { valeur: 2, libelle: 'Réductions de dette' },
  ];
  readonly optionsType = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Tous les types' },
    ...(this.donnees()?.types ?? []).map((t) => ({ valeur: t.id, libelle: t.libelle })),
  ]);
  readonly optionsStatut: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Affectés et non affectés' },
    { valeur: 1, libelle: 'Affectés' },
    { valeur: 2, libelle: 'Non affectés' },
  ];
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
  readonly cycles = computed(() => this.donnees()?.criteres_disponibles.cycles ?? []);
  readonly optionsCycle = computed<OptionSelecteur[]>(() => [{ valeur: 0, libelle: 'Tous' }, ...this.cycles().map((c, i) => ({ valeur: i + 1, libelle: c.libelle }))]);
  readonly optionsNiveau = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Tous' },
    ...(this.donnees()?.criteres_disponibles.niveaux ?? []).map((n) => ({ valeur: n.id, libelle: n.libelle })),
  ]);
  readonly optionsClasse = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Toutes' },
    ...(this.donnees()?.criteres_disponibles.classes ?? []).map((c) => ({ valeur: c.id, libelle: c.libelle })),
  ]);

  readonly valeurCible = computed(() => (this.filtres().cible === 'inscription' ? 1 : this.filtres().cible === 'dette' ? 2 : 0));
  readonly valeurStatut = computed(() => (this.filtres().affecte === true ? 1 : this.filtres().affecte === false ? 2 : 0));
  readonly valeurSexe = computed(() => (this.filtres().sexe === 'F' ? 1 : this.filtres().sexe === 'M' ? 2 : 0));
  readonly valeurRedoublant = computed(() => (this.filtres().redoublant === true ? 1 : this.filtres().redoublant === false ? 2 : 0));
  readonly valeurCycle = computed(() => this.cycles().findIndex((c) => c.valeur === this.filtres().cycle) + 1);
  readonly nombreFiltres = computed(() => {
    const f = this.filtres();
    return [f.cible, f.type_reduction_id, f.du, f.affecte ?? null, f.sexe, f.redoublant ?? null, f.cycle, f.niveau_id, f.classe_id].filter((v) => v !== null && v !== undefined && v !== '').length;
  });
  readonly resumeFiltres = computed(() => {
    const f = this.filtres();
    const d = this.donnees();
    return [
      f.cible === 'inscription' ? 'inscriptions' : f.cible === 'dette' ? 'dettes' : null,
      f.type_reduction_id ? d?.types.find((t) => t.id === f.type_reduction_id)?.libelle : null,
      f.du ? `du ${this.dateFr(f.du)} au ${this.dateFr(f.au || f.du)}` : null,
      f.affecte === true ? 'affectés' : f.affecte === false ? 'non affectés' : null,
      d?.criteres_libelle || null,
    ].filter(Boolean).join(' · ');
  });

  constructor() {
    addIcons({
      pricetagOutline, alertCircleOutline, personAddOutline, printOutline, documentsOutline, optionsOutline, closeOutline, searchOutline, trashOutline, eyeOutline,
      cloudOfflineOutline, peopleOutline, walletOutline, heartOutline, maleFemaleOutline, repeatOutline, layersOutline, schoolOutline, calendarOutline, arrowForwardOutline,
    });
  }

  ionViewWillEnter(): void {
    this.charger();
  }

  charger(): void {
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.liste({ ...this.filtres(), recherche: this.recherche() }).subscribe({
      next: (d) => {
        this.donnees.set(d);
        this.chargement.set(false);
        if ((this.page() - 1) * this.taille() >= d.data.length) this.page.set(1);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  private minuterie?: ReturnType<typeof setTimeout>;
  rechercher(texte: string): void {
    this.recherche.set(texte);
    clearTimeout(this.minuterie);
    this.minuterie = setTimeout(() => {
      this.page.set(1);
      this.charger();
    }, 300);
  }

  // ------------------------------------------------ Filtres

  private maj(changement: Partial<FiltresReductions>): void {
    this.filtres.update((f) => ({ ...f, ...changement }));
    this.page.set(1);
    this.charger();
  }

  choisirCible(v: number): void {
    this.maj({ cible: v === 1 ? 'inscription' : v === 2 ? 'dette' : null, type_reduction_id: v === 2 ? null : this.filtres().type_reduction_id });
  }
  choisirType(id: number): void {
    this.maj({ type_reduction_id: id || null, cible: id ? 'inscription' : this.filtres().cible });
  }
  choisirStatut(v: number): void {
    this.maj({ affecte: v === 1 ? true : v === 2 ? false : null });
  }
  choisirSexe(v: number): void {
    this.maj({ sexe: v === 1 ? 'F' : v === 2 ? 'M' : null });
  }
  choisirRedoublant(v: number): void {
    this.maj({ redoublant: v === 1 ? true : v === 2 ? false : null });
  }
  choisirCycle(rang: number): void {
    this.maj({ cycle: this.cycles()[rang - 1]?.valeur ?? null, niveau_id: null, classe_id: null });
  }
  choisirNiveau(id: number): void {
    this.maj({ niveau_id: id || null, cycle: null, classe_id: null });
  }
  choisirClasse(id: number): void {
    this.maj({ classe_id: id || null, cycle: null, niveau_id: null });
  }
  appliquerDates(): void {
    if (this.au() && this.du() && this.au() < this.du()) {
      this.notifier('La date de fin doit suivre la date de début.', true);
      return;
    }
    this.maj({ du: this.du() || null, au: this.au() || this.du() || null });
  }
  effacerFiltres(): void {
    this.du.set('');
    this.au.set('');
    this.filtres.set({});
    this.page.set(1);
    this.charger();
  }

  // ------------------------------------------------ Matricule puis formulaire

  ouvrirMatricule(cible: CibleReduction): void {
    this.matricule.set('');
    this.erreurMatricule.set(null);
    this.cible.set(cible);
  }

  verifierMatricule(): void {
    const matricule = this.matricule().trim().toUpperCase();
    const cible = this.cible();
    if (!cible) return;
    if (!matricule) {
      this.erreurMatricule.set('Saisissez le matricule de l\'élève.');
      return;
    }
    this.verification.set(true);
    this.erreurMatricule.set(null);
    this.service.eleve(matricule, cible).subscribe({
      next: () => {
        this.verification.set(false);
        this.cible.set(null);
        this.router.navigate(['/tabs/reductions/nouvelle'], { queryParams: { matricule, cible } });
      },
      error: (e: HttpErrorResponse) => {
        this.verification.set(false);
        this.erreurMatricule.set(e.error?.message || 'Vérification impossible.');
      },
    });
  }

  // ------------------------------------------------ Actions

  async supprimer(r: ReductionAccordee): Promise<void> {
    const a = await this.alertes.create({
      header: 'Supprimer la réduction',
      message: `${r.type} de ${this.f(r.montant)} accordée à ${r.nom} ${r.prenoms} : les montants dus seront rétablis.`,
      buttons: [{ text: 'Annuler', role: 'cancel' }, { text: 'Supprimer', role: 'confirm' }],
    });
    await a.present();
    if ((await a.onDidDismiss()).role !== 'confirm') return;
    this.suppression.set(r.lot);
    this.service.supprimer(r.lot).subscribe({
      next: (x) => {
        this.suppression.set(null);
        this.notifier(x.message);
        this.charger();
      },
      error: (e: HttpErrorResponse) => {
        this.suppression.set(null);
        this.notifier(e.error?.message || 'Suppression impossible.', true);
      },
    });
  }

  async imprimer(general = false): Promise<void> {
    this.impression.set(general ? 'general' : 'liste');
    await this.service.imprimer({ ...this.filtres(), recherche: this.recherche() }, general);
    this.impression.set(null);
  }

  voirEleve(r: ReductionAccordee): void {
    this.router.navigate(['/tabs/eleves', r.eleve_id]);
  }

  // ------------------------------------------------ Affichage

  f(v: number): string {
    return v.toLocaleString('fr-FR') + ' F';
  }

  dateFr(d: string): string {
    return d ? d.split('-').reverse().join('/') : '';
  }

  valeurTexte(e: Event): string {
    return (e.target as HTMLInputElement).value;
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}

import { Component, OnInit, computed, effect, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import {
  AlertController,
  ToastController,
  IonContent,
  IonIcon,
  IonModal,
  IonSkeletonText,
  IonRefresher,
  IonRefresherContent,
  IonSpinner,
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  addOutline,
  searchOutline,
  createOutline,
  eyeOutline,
  powerOutline,
  refreshOutline,
  briefcaseOutline,
  checkmarkCircleOutline,
  closeCircleOutline,
  lockClosed,
  lockClosedOutline,
  cloudOfflineOutline,
  ellipseOutline,
  closeOutline,
  peopleOutline,
  keyOutline,
  layersOutline,
  bookOutline,
  shieldCheckmarkOutline,
  checkmarkOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { PosteService } from '../../core/services/poste.service';
import { CatalogueDroits, Poste, PosteDetail } from '../../core/models/poste.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE, paginer } from '../../shared/pagination.component';
import { PosteFormulaireComponent } from './poste-formulaire.component';

/**
 * Administration des postes (menu "Rôles", droit "roles.gerer") : postes
 * standard et postes crees par l'ecole, avec leurs droits. Comme en V1, un
 * poste ne se supprime pas : il se desactive.
 */
@Component({
  selector: 'app-roles',
  standalone: true,
  imports: [
    IonContent,
    IonIcon,
    IonModal,
    IonSkeletonText,
    IonRefresher,
    IonRefresherContent,
    IonSpinner,
    SelecteurComponent,
    PaginationComponent,
    PosteFormulaireComponent,
  ],
  templateUrl: './roles.page.html',
  styleUrl: './roles.page.scss',
})
export class RolesPage implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly service = inject(PosteService);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);

  readonly etablissement = this.auth.etablissementActif;

  readonly postes = signal<Poste[]>([]);
  readonly catalogue = signal<CatalogueDroits | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal(false);
  readonly actionEnCours = signal<number | null>(null);

  readonly recherche = signal('');
  readonly filtreStatut = signal(0);

  readonly formulaireOuvert = signal(false);
  readonly posteEdite = signal<Poste | null>(null);
  readonly detail = signal<PosteDetail | null>(null);
  readonly detailOuvert = signal(false);
  readonly chargementDetail = signal(false);

  readonly optionsStatuts: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Tous les postes' },
    { valeur: 1, libelle: 'Actifs', badge: { texte: 'Actif', ton: 'succes' } },
    { valeur: 2, libelle: 'Désactivés', badge: { texte: 'Inactif', ton: 'neutre' } },
  ];

  readonly compteurs = computed(() => {
    const liste = this.postes();
    return {
      total: liste.length,
      actifs: liste.filter((p) => p.is_active).length,
      inactifs: liste.filter((p) => !p.is_active).length,
      proteges: liste.filter((p) => p.est_sensible).length,
    };
  });

  readonly postesFiltres = computed(() => {
    const termes = this.normaliser(this.recherche()).split(/\s+/).filter(Boolean);
    const statut = this.filtreStatut();
    return this.postes().filter((p) => {
      if ((statut === 1 && !p.is_active) || (statut === 2 && p.is_active)) {
        return false;
      }
      const texte = this.normaliser(`${p.nom} ${p.description ?? ''}`);
      return termes.every((t) => texte.includes(t));
    });
  });

  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);
  readonly postesPage = computed(() => paginer(this.postesFiltres(), this.page(), this.taille()));

  readonly filtresActifs = computed(() => !!this.recherche().trim() || !!this.filtreStatut());

  /** Libelles des droits, pour l'affichage du detail. */
  readonly libellesDroits = computed(() => {
    const libelles = new Map<string, string>();
    this.catalogue()?.groupes.forEach((g) => g.droits.forEach((d) => libelles.set(d.nom, d.libelle)));
    return libelles;
  });

  constructor() {
    // Nouveau filtre : retour a la premiere page.
    effect(
      () => {
        this.recherche();
        this.filtreStatut();
        this.page.set(1);
      },
      { allowSignalWrites: true },
    );
    addIcons({
      addOutline,
      searchOutline,
      createOutline,
      eyeOutline,
      powerOutline,
      refreshOutline,
      briefcaseOutline,
      checkmarkCircleOutline,
      closeCircleOutline,
      lockClosed,
      lockClosedOutline,
      cloudOfflineOutline,
      ellipseOutline,
      closeOutline,
      peopleOutline,
      keyOutline,
      layersOutline,
      bookOutline,
      shieldCheckmarkOutline,
      checkmarkOutline,
    });
  }

  ngOnInit(): void {
    this.charger();
    this.service.catalogue().subscribe({ next: (c) => this.catalogue.set(c) });
  }

  charger(evenement?: CustomEvent): void {
    this.erreur.set(false);
    this.service.lister().subscribe({
      next: (liste) => {
        this.postes.set(liste);
        this.chargement.set(false);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
    });
  }

  reinitialiserFiltres(): void {
    this.recherche.set('');
    this.filtreStatut.set(0);
  }

  // ------------------------------------------------------------------ Detail

  voir(p: Poste): void {
    this.detail.set({ ...p, utilisateurs: [] });
    this.detailOuvert.set(true);
    this.chargementDetail.set(true);
    this.service.detail(p.id).subscribe({
      next: (d) => {
        this.detail.set(d);
        this.chargementDetail.set(false);
      },
      error: () => this.chargementDetail.set(false),
    });
  }

  /** Droits du poste regroupes comme dans le catalogue. */
  droitsParGroupe(p: Poste): { groupe: string; droits: string[] }[] {
    const catalogue = this.catalogue();
    if (!catalogue) {
      return [];
    }
    return catalogue.groupes
      .map((g) => ({
        groupe: g.groupe,
        droits: g.droits.filter((d) => p.permissions.includes(d.nom)).map((d) => d.libelle),
      }))
      .filter((g) => g.droits.length);
  }

  modifierDepuisDetail(): void {
    const d = this.detail();
    this.detailOuvert.set(false);
    if (d) {
      this.modifier(d);
    }
  }

  // ------------------------------------------------------------------ Formulaire

  nouveau(): void {
    this.posteEdite.set(null);
    this.formulaireOuvert.set(true);
  }

  modifier(p: Poste): void {
    if (!p.modifiable) {
      return;
    }
    this.posteEdite.set(p);
    this.formulaireOuvert.set(true);
  }

  surEnregistrement(p: Poste, creation: boolean): void {
    this.formulaireOuvert.set(false);
    if (creation) {
      this.postes.update((liste) => [...liste, p]);
      this.notifier(`Poste « ${p.nom} » créé.`);
    } else {
      this.remplacer(p);
      this.notifier('Modifications enregistrées.');
    }
  }

  // ------------------------------------------------------------------ Actions

  async basculerStatut(p: Poste): Promise<void> {
    const desactiver = p.is_active;
    const utilisateurs = p.nombre_utilisateurs;
    const confirme = await this.confirmer(
      desactiver ? 'Désactiver ce poste ?' : 'Réactiver ce poste ?',
      desactiver
        ? `« ${p.nom} » ne pourra plus être attribué ni choisi à la connexion.` +
            (utilisateurs
              ? ` ${utilisateurs} compte${utilisateurs > 1 ? 's' : ''} le garde${utilisateurs > 1 ? 'nt' : ''} mais ne pourr${utilisateurs > 1 ? 'ont' : 'a'} plus travailler avec ce poste.`
              : '')
        : `« ${p.nom} » pourra de nouveau être attribué et choisi à la connexion.`,
      desactiver ? 'Désactiver' : 'Réactiver',
      desactiver,
    );
    if (!confirme) {
      return;
    }
    this.actionEnCours.set(p.id);
    this.service.changerStatut(p.id, !desactiver).subscribe({
      next: (maj) => {
        this.actionEnCours.set(null);
        this.remplacer(maj);
        this.notifier(desactiver ? 'Poste désactivé.' : 'Poste réactivé.');
      },
      error: (e: HttpErrorResponse) => this.echecAction(e),
    });
  }

  nomUtilisateur(u: PosteDetail['utilisateurs'][number]): string {
    return [u.prenoms, u.nom].filter(Boolean).join(' ');
  }

  // ------------------------------------------------------------------ Interne

  private remplacer(p: Poste): void {
    this.postes.update((liste) => liste.map((x) => (x.id === p.id ? { ...x, ...p } : x)));
  }

  private echecAction(erreur: HttpErrorResponse): void {
    this.actionEnCours.set(null);
    const message =
      (erreur.error?.errors && (Object.values(erreur.error.errors)[0] as string[])[0]) ||
      (erreur.status === 403 ? erreur.error?.message || "Vous n'avez pas le droit de modifier ce poste." : null) ||
      'Action impossible. Vérifiez votre connexion puis réessayez.';
    this.notifier(message, true);
  }

  private async confirmer(titre: string, message: string, action: string, danger = false): Promise<boolean> {
    const alerte = await this.alertes.create({
      header: titre,
      message,
      cssClass: 'alerte-me',
      buttons: [
        { text: 'Annuler', role: 'cancel' },
        { text: action, role: 'confirm', cssClass: danger ? 'bouton-danger' : '' },
      ],
    });
    await alerte.present();
    const { role } = await alerte.onDidDismiss();
    return role === 'confirm';
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }

  private normaliser(texte: string | null | undefined): string {
    return (texte ?? '')
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase();
  }
}

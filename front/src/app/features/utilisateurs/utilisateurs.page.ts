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
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  addOutline,
  searchOutline,
  createOutline,
  keyOutline,
  powerOutline,
  refreshOutline,
  peopleOutline,
  checkmarkCircleOutline,
  closeCircleOutline,
  timeOutline,
  copyOutline,
  checkmarkOutline,
  shieldCheckmarkOutline,
  cloudOfflineOutline,
  briefcaseOutline,
  ellipseOutline,
  closeOutline,
  lockClosed,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { UtilisateurService } from '../../core/services/utilisateur.service';
import { OptionsUtilisateurs, Utilisateur, UtilisateurAvecMotDePasse } from '../../core/models/utilisateur.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE, paginer } from '../../shared/pagination.component';
import { UtilisateurFormulaireComponent } from './utilisateur-formulaire.component';

interface MotDePasseAffiche {
  utilisateur: Utilisateur;
  motDePasse: string;
  contexte: 'creation' | 'reinitialisation';
}

/**
 * Administration des utilisateurs de l'etablissement (droit
 * "utilisateurs.gerer") : liste, recherche/filtres, creation, modification,
 * reinitialisation du mot de passe, activation/desactivation.
 */
@Component({
  selector: 'app-utilisateurs',
  standalone: true,
  imports: [
    IonContent,
    IonIcon,
    IonModal,
    IonSkeletonText,
    IonRefresher,
    IonRefresherContent,
    SelecteurComponent,
    PaginationComponent,
    UtilisateurFormulaireComponent,
  ],
  templateUrl: './utilisateurs.page.html',
  styleUrl: './utilisateurs.page.scss',
})
export class UtilisateursPage implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly service = inject(UtilisateurService);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);

  readonly etablissement = this.auth.etablissementActif;

  readonly utilisateurs = signal<Utilisateur[]>([]);
  readonly options = signal<OptionsUtilisateurs | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal(false);
  /** Utilisateur dont une action est en cours (desactive ses boutons). */
  readonly actionEnCours = signal<number | null>(null);

  readonly recherche = signal('');
  readonly filtrePoste = signal(0);
  readonly filtreStatut = signal(0);

  readonly formulaireOuvert = signal(false);
  readonly utilisateurEdite = signal<Utilisateur | null>(null);
  readonly motDePasse = signal<MotDePasseAffiche | null>(null);
  readonly copie = signal(false);

  readonly optionsPostes = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Tous les postes' },
    ...(this.options()?.postes ?? []).map((p) => ({ valeur: p.id, libelle: p.nom, detail: p.is_active ? null : 'Désactivé' })),
  ]);

  readonly optionsStatuts: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Tous les statuts' },
    { valeur: 1, libelle: 'Actifs', badge: { texte: 'Actif', ton: 'succes' } },
    { valeur: 2, libelle: 'Désactivés', badge: { texte: 'Inactif', ton: 'neutre' } },
  ];

  readonly compteurs = computed(() => {
    const liste = this.utilisateurs();
    return {
      total: liste.length,
      actifs: liste.filter((u) => u.statut === 'actif').length,
      inactifs: liste.filter((u) => u.statut === 'inactif').length,
      premiereConnexion: liste.filter((u) => u.statut === 'actif' && u.doit_changer_mot_de_passe).length,
    };
  });

  /** Filtrage local : quelques dizaines de comptes par etablissement. */
  readonly utilisateursFiltres = computed(() => {
    const termes = this.normaliser(this.recherche()).split(/\s+/).filter(Boolean);
    const poste = this.filtrePoste();
    const statut = this.filtreStatut() === 1 ? 'actif' : this.filtreStatut() === 2 ? 'inactif' : null;

    return this.utilisateurs().filter((u) => {
      if (poste && !u.postes.some((p) => p.id === poste)) {
        return false;
      }
      if (statut && u.statut !== statut) {
        return false;
      }
      const texte = this.normaliser(
        [u.nom, u.prenoms, u.email, u.matricule, u.telephone, ...u.postes.map((p) => p.nom)].join(' '),
      );
      return termes.every((t) => texte.includes(t));
    });
  });

  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);
  readonly utilisateursPage = computed(() => paginer(this.utilisateursFiltres(), this.page(), this.taille()));

  readonly filtresActifs = computed(() => !!this.recherche().trim() || !!this.filtrePoste() || !!this.filtreStatut());

  constructor() {
    // Nouveau filtre : retour a la premiere page.
    effect(
      () => {
        this.recherche();
        this.filtrePoste();
        this.filtreStatut();
        this.page.set(1);
      },
      { allowSignalWrites: true },
    );
    addIcons({
      addOutline,
      searchOutline,
      createOutline,
      keyOutline,
      powerOutline,
      refreshOutline,
      peopleOutline,
      checkmarkCircleOutline,
      closeCircleOutline,
      timeOutline,
      copyOutline,
      checkmarkOutline,
      shieldCheckmarkOutline,
      cloudOfflineOutline,
      briefcaseOutline,
      ellipseOutline,
      closeOutline,
      lockClosed,
    });
  }

  ngOnInit(): void {
    this.charger();
    this.service.options().subscribe({ next: (options) => this.options.set(options) });
  }

  charger(evenement?: CustomEvent): void {
    this.erreur.set(false);
    this.service.lister().subscribe({
      next: (liste) => {
        this.utilisateurs.set(liste);
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
    this.filtrePoste.set(0);
    this.filtreStatut.set(0);
  }

  // ------------------------------------------------------------------ Formulaire

  nouveau(): void {
    this.utilisateurEdite.set(null);
    this.formulaireOuvert.set(true);
  }

  modifier(u: Utilisateur): void {
    if (!this.peutGerer(u)) {
      return;
    }
    this.utilisateurEdite.set(u);
    this.formulaireOuvert.set(true);
  }

  surModification(u: Utilisateur): void {
    this.formulaireOuvert.set(false);
    this.remplacer(u);
    this.notifier('Modifications enregistrées.');
  }

  surCreation(reponse: UtilisateurAvecMotDePasse): void {
    this.formulaireOuvert.set(false);
    this.utilisateurs.update((liste) =>
      [...liste, reponse.data].sort((a, b) => (a.nom + ' ' + a.prenoms).localeCompare(b.nom + ' ' + b.prenoms, 'fr')),
    );
    this.afficherMotDePasse(reponse, 'creation');
  }

  // ------------------------------------------------------------------ Actions

  /** Un compte avec un poste protege (caisse, comptabilite...) n'est gere
   * que par le Super admin (verifie aussi par l'API). */
  peutGerer(u: Utilisateur): boolean {
    return u.modifiable;
  }

  async reinitialiserMotDePasse(u: Utilisateur): Promise<void> {
    const confirme = await this.confirmer(
      'Réinitialiser le mot de passe ?',
      `Un nouveau mot de passe provisoire sera généré pour ${this.nomComplet(u)}. Son ancien mot de passe ne fonctionnera plus et il sera déconnecté de tous ses appareils.`,
      'Réinitialiser',
    );
    if (!confirme) {
      return;
    }
    this.actionEnCours.set(u.id);
    this.service.reinitialiserMotDePasse(u.id).subscribe({
      next: (reponse) => {
        this.actionEnCours.set(null);
        this.remplacer(reponse.data);
        this.afficherMotDePasse(reponse, 'reinitialisation');
      },
      error: (e: HttpErrorResponse) => this.echecAction(e),
    });
  }

  async basculerStatut(u: Utilisateur): Promise<void> {
    const desactiver = u.statut === 'actif';
    const confirme = await this.confirmer(
      desactiver ? 'Désactiver ce compte ?' : 'Réactiver ce compte ?',
      desactiver
        ? `${this.nomComplet(u)} ne pourra plus se connecter et sera déconnecté immédiatement. Son historique (paiements, notes, absences saisis…) est conservé.`
        : `${this.nomComplet(u)} pourra de nouveau se connecter avec son mot de passe actuel.`,
      desactiver ? 'Désactiver' : 'Réactiver',
      desactiver,
    );
    if (!confirme) {
      return;
    }
    this.actionEnCours.set(u.id);
    this.service.changerStatut(u.id, desactiver ? 'inactif' : 'actif').subscribe({
      next: (maj) => {
        this.actionEnCours.set(null);
        this.remplacer(maj);
        this.notifier(desactiver ? 'Compte désactivé.' : 'Compte réactivé.');
      },
      error: (e: HttpErrorResponse) => this.echecAction(e),
    });
  }

  async copierMotDePasse(): Promise<void> {
    const affiche = this.motDePasse();
    if (!affiche) {
      return;
    }
    try {
      await navigator.clipboard.writeText(affiche.motDePasse);
      this.copie.set(true);
      setTimeout(() => this.copie.set(false), 2000);
    } catch {
      this.notifier('Copie impossible : sélectionnez le mot de passe manuellement.');
    }
  }

  // ------------------------------------------------------------------ Affichage

  nomComplet(u: Utilisateur): string {
    return [u.prenoms, u.nom].filter(Boolean).join(' ');
  }

  initiales(u: Utilisateur): string {
    return ((u.prenoms?.trim().charAt(0) ?? '') + (u.nom?.trim().charAt(0) ?? '')).toUpperCase() || '?';
  }

  derniereConnexion(u: Utilisateur): string {
    if (!u.derniere_connexion_at) {
      return 'Jamais connecté';
    }
    const date = new Date(u.derniere_connexion_at);
    const jours = Math.floor((Date.now() - date.getTime()) / 86_400_000);
    if (jours <= 0) {
      return "Aujourd'hui, " + date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    }
    if (jours === 1) {
      return 'Hier';
    }
    if (jours < 7) {
      return `Il y a ${jours} jours`;
    }
    return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  // ------------------------------------------------------------------ Interne

  private afficherMotDePasse(reponse: UtilisateurAvecMotDePasse, contexte: MotDePasseAffiche['contexte']): void {
    this.copie.set(false);
    this.motDePasse.set({ utilisateur: reponse.data, motDePasse: reponse.mot_de_passe_provisoire, contexte });
  }

  private remplacer(u: Utilisateur): void {
    this.utilisateurs.update((liste) => liste.map((x) => (x.id === u.id ? u : x)));
  }

  private echecAction(erreur: HttpErrorResponse): void {
    this.actionEnCours.set(null);
    const message =
      (erreur.error?.errors && (Object.values(erreur.error.errors)[0] as string[])[0]) ||
      (erreur.status === 403 ? erreur.error?.message || "Vous n'avez pas le droit de modifier ce compte." : null) ||
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
    const toast = await this.toasts.create({
      message,
      duration: 3000,
      position: 'bottom',
      color: erreur ? 'danger' : 'dark',
    });
    await toast.present();
  }

  private normaliser(texte: string | null | undefined): string {
    return (texte ?? '')
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase();
  }
}

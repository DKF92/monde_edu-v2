import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import {
  AlertController,
  ToastController,
  IonContent,
  IonIcon,
  IonModal,
  IonSkeletonText,
  IonSpinner,
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  chevronDownOutline,
  addOutline,
  calendarOutline,
  calendarNumberOutline,
  createOutline,
  lockClosedOutline,
  lockOpenOutline,
  playCircleOutline,
  closeOutline,
  checkbox,
  squareOutline,
  informationCircleOutline,
  cloudOfflineOutline,
  alertCircleOutline,
  gitCompareOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { ParametreService } from '../../core/services/parametre.service';
import { AnneeGestion, NouvelleAnnee, PeriodeGestion } from '../../core/models/parametre.model';
import { PaginationComponent, TAILLES_PAGE, paginer } from '../../shared/pagination.component';

interface EditionDates {
  cible: 'annee' | 'periode';
  id: number;
  titre: string;
  date_debut: string;
  date_fin: string;
}

/**
 * Parametres > Annees scolaires (V1 : tables "annee" et "trimestre").
 * Une annee en cours (proposee a la connexion), une periode en cours par
 * annee ; cloturer bloque la saisie ; pas de suppression.
 */
@Component({
  selector: 'app-parametres-annees',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, PaginationComponent],
  templateUrl: './parametres-annees.page.html',
  styleUrls: ['./parametres.commun.scss', './parametres-annees.page.scss'],
})
export class ParametresAnneesPage implements OnInit {
  private readonly service = inject(ParametreService);
  private readonly toasts = inject(ToastController);
  private readonly alertes = inject(AlertController);
  readonly router = inject(Router);
  /** Clore une periode : reserve au directeur (droit notes.arreter). */
  readonly peutCloturer = inject(AuthService).aPermission('notes.arreter');

  readonly annees = signal<AnneeGestion[]>([]);
  readonly chargement = signal(true);
  readonly erreur = signal(false);
  readonly actionEnCours = signal<string | null>(null);
  readonly ouvertes = signal<Set<number>>(new Set());

  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);
  readonly anneesPage = computed(() => paginer(this.annees(), this.page(), this.taille()));
  readonly enCours = computed(() => this.annees().find((a) => a.is_active) ?? null);
  readonly plusRecente = computed<AnneeGestion | null>(() => this.annees()[0] ?? null);

  // Nouvelle annee
  readonly creationOuverte = signal(false);
  readonly nouvelle = signal<NouvelleAnnee>(this.anneeVide());
  readonly erreursCreation = signal<Record<string, string>>({});
  readonly creation = signal(false);

  // Dates d'une annee / periode
  readonly dates = signal<EditionDates | null>(null);
  readonly erreurDates = signal<string | null>(null);
  readonly enregistrementDates = signal(false);

  constructor() {
    addIcons({
      chevronBackOutline,
      chevronDownOutline,
      addOutline,
      calendarOutline,
      calendarNumberOutline,
      createOutline,
      lockClosedOutline,
      lockOpenOutline,
      playCircleOutline,
      closeOutline,
      checkbox,
      squareOutline,
      informationCircleOutline,
      cloudOfflineOutline,
      alertCircleOutline,
      gitCompareOutline,
    });
  }

  ngOnInit(): void {
    this.charger();
  }

  charger(): void {
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.annees().subscribe({
      next: (liste) => {
        this.annees.set(liste);
        // L'annee en cours est depliee par defaut.
        this.ouvertes.set(new Set(liste.filter((a) => a.is_active).map((a) => a.id)));
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  basculer(id: number): void {
    this.ouvertes.update((set) => {
      const suivant = new Set(set);
      suivant.has(id) ? suivant.delete(id) : suivant.add(id);
      return suivant;
    });
  }

  // ------------------------------------------------------------ Nouvelle annee

  ouvrirCreation(): void {
    const derniere = this.plusRecente()?.libelle;
    const debut = derniere ? Number(derniere.slice(5)) : new Date().getFullYear();
    this.nouvelle.set({
      ...this.anneeVide(),
      libelle: `${debut}-${debut + 1}`,
      date_debut: `${debut}-09-15`,
      date_fin: `${debut + 1}-07-15`,
      type_decoupage: this.enCours()?.type_decoupage ?? 'trimestre',
      copier_classes: !!this.plusRecente()?.nombre_classes,
      copier_tarifs: !!this.plusRecente(),
    });
    this.erreursCreation.set({});
    this.creationOuverte.set(true);
  }

  majNouvelle(modif: Partial<NouvelleAnnee>): void {
    this.nouvelle.update((n) => ({ ...n, ...modif }));
    Object.keys(modif).forEach((cle) => {
      if (this.erreursCreation()[cle]) {
        const { [cle]: _, ...reste } = this.erreursCreation();
        this.erreursCreation.set(reste);
      }
    });
  }

  creer(): void {
    const n = this.nouvelle();
    if (!/^\d{4}-\d{4}$/.test(n.libelle)) {
      this.erreursCreation.set({ libelle: 'Format attendu : 2026-2027.' });
      return;
    }
    this.creation.set(true);
    this.service.creerAnnee({ ...n, date_debut: n.date_debut || null, date_fin: n.date_fin || null }).subscribe({
      next: (a) => {
        this.creation.set(false);
        this.creationOuverte.set(false);
        this.notifier(`Année ${a.libelle} créée${n.activer ? ' et mise en cours' : ''}.`);
        this.charger();
      },
      error: (e: HttpErrorResponse) => {
        this.creation.set(false);
        if (e.status === 422 && e.error?.errors) {
          this.erreursCreation.set(Object.fromEntries(Object.entries(e.error.errors as Record<string, string[]>).map(([k, v]) => [k, v[0]])));
        } else {
          this.notifier(this.premiereErreur(e), true);
        }
      },
    });
  }

  // ------------------------------------------------------------ Actions annee

  async activer(a: AnneeGestion): Promise<void> {
    const ok = await this.confirmer(
      `Mettre ${a.libelle} en cours ?`,
      `${a.libelle} sera proposée par défaut à la connexion et au tableau de bord. ${this.enCours() ? this.enCours()!.libelle + ' reste consultable.' : ''}`,
      'Mettre en cours',
    );
    if (!ok) {
      return;
    }
    this.actionEnCours.set('a' + a.id);
    this.service.activerAnnee(a.id).subscribe({
      next: (liste) => {
        this.actionEnCours.set(null);
        this.annees.set(liste);
        this.notifier(`${a.libelle} est l'année en cours.`);
      },
      error: (e: HttpErrorResponse) => this.echec(e),
    });
  }

  async cloturer(a: AnneeGestion): Promise<void> {
    const cloturer = !a.is_cloturee;
    const ok = await this.confirmer(
      cloturer ? `Clôturer ${a.libelle} ?` : `Rouvrir ${a.libelle} ?`,
      cloturer
        ? 'Plus aucune saisie (inscriptions, notes, paiements) ne sera possible sur cette année. Vous pourrez la rouvrir si besoin.'
        : 'Les saisies seront de nouveau possibles sur cette année.',
      cloturer ? 'Clôturer' : 'Rouvrir',
      cloturer,
    );
    if (!ok) {
      return;
    }
    this.actionEnCours.set('a' + a.id);
    this.service.cloturerAnnee(a.id, cloturer).subscribe({
      next: (maj) => {
        this.actionEnCours.set(null);
        this.remplacer(maj);
        this.notifier(cloturer ? `${a.libelle} clôturée.` : `${a.libelle} rouverte.`);
      },
      error: (e: HttpErrorResponse) => this.echec(e),
    });
  }

  /**
   * Trimestres <-> semestres, meme en cours d'annee : les 2 premieres periodes
   * sont conservees (avec leurs notes), la 3e est creee ou supprimee.
   */
  async changerDecoupage(a: AnneeGestion): Promise<void> {
    const alerte = await this.alertes.create({
      header: `Découpage de ${a.libelle}`,
      message:
        a.type_decoupage === 'trimestre'
          ? 'Passer en semestres : les 1er et 2e trimestres deviennent les 1er et 2e semestres (leurs notes sont conservées) ; le 3e trimestre est supprimé s\'il ne contient aucune note.'
          : a.type_decoupage === 'semestre'
            ? 'Passer en trimestres : les 1er et 2e semestres deviennent les 1er et 2e trimestres (leurs notes sont conservées) ; un 3e trimestre est ajouté.'
            : 'Choisissez le découpage de cette année.',
      cssClass: 'alerte-me',
      inputs: [
        { type: 'radio', label: '3 trimestres', value: 'trimestre', checked: a.type_decoupage !== 'semestre' },
        { type: 'radio', label: '2 semestres', value: 'semestre', checked: a.type_decoupage === 'semestre' },
      ],
      buttons: [
        { text: 'Annuler', role: 'cancel' },
        { text: 'Appliquer', role: 'confirm' },
      ],
    });
    await alerte.present();
    const { role, data } = await alerte.onDidDismiss();
    const type = data?.values as 'trimestre' | 'semestre' | undefined;
    if (role !== 'confirm' || !type || (type === a.type_decoupage && a.periodes.length)) {
      return;
    }
    this.actionEnCours.set('a' + a.id);
    this.service.changerDecoupage(a.id, type).subscribe({
      next: (maj) => {
        this.actionEnCours.set(null);
        this.remplacer(maj);
        this.ouvertes.update((set) => new Set(set).add(a.id));
        this.notifier(`${a.libelle} découpée en ${type === 'trimestre' ? '3 trimestres' : '2 semestres'}.`);
      },
      error: (e: HttpErrorResponse) => this.echec(e),
    });
  }

  // ------------------------------------------------------------ Actions periode

  activerPeriode(a: AnneeGestion, p: PeriodeGestion): void {
    this.actionEnCours.set('p' + p.id);
    this.service.activerPeriode(p.id).subscribe({
      next: (maj) => {
        this.actionEnCours.set(null);
        this.remplacer(maj);
        this.notifier(`${p.libelle} ${a.libelle} en cours.`);
      },
      error: (e: HttpErrorResponse) => this.echec(e),
    });
  }


  // ------------------------------------------------------------ Dates

  modifierDates(cible: 'annee' | 'periode', objet: AnneeGestion | PeriodeGestion, titre: string): void {
    this.erreurDates.set(null);
    this.dates.set({ cible, id: objet.id, titre, date_debut: objet.date_debut ?? '', date_fin: objet.date_fin ?? '' });
  }

  majDates(modif: Partial<EditionDates>): void {
    this.dates.update((d) => (d ? { ...d, ...modif } : d));
    this.erreurDates.set(null);
  }

  enregistrerDates(): void {
    const d = this.dates();
    if (!d) {
      return;
    }
    if (d.date_debut && d.date_fin && d.date_fin <= d.date_debut) {
      this.erreurDates.set('La date de fin doit être après la date de début.');
      return;
    }
    const valeurs = { date_debut: d.date_debut || null, date_fin: d.date_fin || null };
    this.enregistrementDates.set(true);
    const requete = d.cible === 'annee' ? this.service.modifierAnnee(d.id, valeurs) : this.service.modifierPeriode(d.id, valeurs);
    requete.subscribe({
      next: (maj) => {
        this.enregistrementDates.set(false);
        this.dates.set(null);
        this.remplacer(maj);
        this.notifier('Dates enregistrées.');
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrementDates.set(false);
        this.erreurDates.set(this.premiereErreur(e));
      },
    });
  }

  // ------------------------------------------------------------ Affichage

  periode(a: AnneeGestion, cle: 'libelle'): string {
    return a.periodes.find((p) => p.is_active)?.[cle] ?? '—';
  }

  intervalle(debut: string | null, fin: string | null): string {
    const f = (d: string) => new Date(d).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' });
    if (!debut && !fin) {
      return 'Dates non renseignées';
    }
    return `${debut ? f(debut) : '…'} → ${fin ? f(fin) : '…'}`;
  }

  // ------------------------------------------------------------ Interne

  private remplacer(a: AnneeGestion): void {
    this.annees.update((liste) => liste.map((x) => (x.id === a.id ? a : x)));
  }

  private anneeVide(): NouvelleAnnee {
    return {
      libelle: '',
      date_debut: null,
      date_fin: null,
      type_decoupage: 'trimestre',
      copier_classes: true,
      copier_tarifs: true,
      activer: false,
    };
  }

  private echec(e: HttpErrorResponse): void {
    this.actionEnCours.set(null);
    this.notifier(this.premiereErreur(e), true);
  }

  private premiereErreur(e: HttpErrorResponse): string {
    return (
      (e.error?.errors && (Object.values(e.error.errors)[0] as string[])[0]) ||
      (e.status === 403 ? "Vous n'avez pas le droit de modifier les années scolaires." : null) ||
      'Action impossible. Vérifiez votre connexion puis réessayez.'
    );
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
    return (await alerte.onDidDismiss()).role === 'confirm';
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}

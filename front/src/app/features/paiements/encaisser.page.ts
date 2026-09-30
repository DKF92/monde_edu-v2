import { Component, OnDestroy, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, ToastController, IonContent, IonIcon, IonSpinner, IonSkeletonText } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  searchOutline,
  cashOutline,
  printOutline,
  checkmarkCircle,
  checkmarkCircleOutline,
  ellipseOutline,
  chatbubbleEllipsesOutline,
  warningOutline,
  alertCircleOutline,
  informationCircleOutline,
  personOutline,
  receiptOutline,
  closeOutline,
  addOutline,
  cubeOutline,
  flashOutline,
  eyeOutline,
} from 'ionicons/icons';
import { Subscription } from 'rxjs';
import { CaisseService } from '../../core/services/caisse.service';
import { ActionsCaisseService } from '../../core/services/actions-caisse.service';
import { LIBELLES_MODE, ModePaiement, ResultatCaisse, ResultatEncaissement, SituationCaisse } from '../../core/models/caisse.model';
import { LIBELLES_STATUT, TONS_STATUT } from '../../core/models/inscription.model';

/**
 * Guichet (V1 modal_paiement) : recherche de l'eleve, situation (frais par
 * ordre de priorite, dettes, versements), saisie du versement et du montant
 * de la dette, date, validite, fournitures ; recu PDF et SMS au parent.
 */
@Component({
  selector: 'app-encaisser',
  standalone: true,
  imports: [IonContent, IonIcon, IonSpinner, IonSkeletonText],
  templateUrl: './encaisser.page.html',
  styleUrl: './encaisser.page.scss',
})
export class EncaisserPage implements OnDestroy {
  private readonly caisse = inject(CaisseService);
  private readonly actions = inject(ActionsCaisseService);
  private readonly route = inject(ActivatedRoute);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly libellesMode = LIBELLES_MODE;
  readonly modes = Object.keys(LIBELLES_MODE) as ModePaiement[];
  readonly libellesStatut = LIBELLES_STATUT;
  readonly tons = TONS_STATUT;

  // ------------------------------------------------ Matricule
  readonly matriculeSaisi = signal('');
  readonly erreurMatricule = signal<string | null>(null);
  readonly recherche = signal('');
  readonly resultats = signal<ResultatCaisse[] | null>(null);
  readonly rechercheEnCours = signal(false);

  // ------------------------------------------------ Situation et saisie
  readonly situation = signal<SituationCaisse | null>(null);
  readonly chargement = signal(false);
  readonly montant = signal<number | null>(null);
  readonly montantDette = signal<number | null>(null);
  readonly mode = signal<ModePaiement>('especes');
  readonly maxDate = this.aujourdhui();
  readonly datePaiement = signal(this.maxDate);
  readonly dateExpiration = signal<string | null>(null);
  readonly remises = signal<Record<number, number>>({});
  readonly erreurs = signal<Record<string, string>>({});
  readonly enregistrement = signal(false);
  readonly resultat = signal<ResultatEncaissement | null>(null);

  readonly totalDettes = computed(() => (this.situation()?.dettes ?? []).reduce((s, d) => s + d.reste, 0));
  readonly totalVerse = computed(() => (this.montant() ?? 0) + (this.montantDette() ?? 0));
  /** Reste apres ce versement (apercu). */
  readonly resteApres = computed(() => Math.max(0, (this.situation()?.reste ?? 0) - (this.montant() ?? 0)));
  /** Repartition previsionnelle du versement sur les frais (meme ordre que l'API). */
  readonly repartition = computed(() => {
    let aRepartir = this.montant() ?? 0;
    return (this.situation()?.frais ?? [])
      .filter((f) => f.reste > 0)
      .map((f) => {
        const part = Math.min(aRepartir, f.reste);
        aRepartir -= part;
        return { ...f, part };
      });
  });

  /** Part du versement qui ira sur ce frais (apercu). */
  part(fraisId: number): number {
    return this.repartition().find((x) => x.id === fraisId)?.part ?? 0;
  }

  private minuterie?: ReturnType<typeof setTimeout>;
  private abonnement?: Subscription;

  constructor() {
    addIcons({
      chevronBackOutline,
      searchOutline,
      cashOutline,
      printOutline,
      checkmarkCircle,
      checkmarkCircleOutline,
      ellipseOutline,
      chatbubbleEllipsesOutline,
      warningOutline,
      alertCircleOutline,
      informationCircleOutline,
      personOutline,
      receiptOutline,
      closeOutline,
      addOutline,
      cubeOutline,
      flashOutline,
      eyeOutline,
    });
  }

  /** Arrivee depuis une inscription (?inscription=id) ou retour sur la page. */
  ionViewWillEnter(): void {
    const id = Number(this.route.snapshot.queryParamMap.get('inscription')) || null;
    if (id && id !== this.situation()?.id) {
      this.choisir(id);
    } else if (!id) {
      // Arrivee par le menu : le matricule est redemande a chaque fois.
      this.situation.set(null);
      this.resultat.set(null);
      this.matriculeSaisi.set('');
      this.erreurMatricule.set(null);
    }
  }

  ngOnDestroy(): void {
    clearTimeout(this.minuterie);
    this.abonnement?.unsubscribe();
  }

  // ================================================================ Recherche

  rechercher(texte: string): void {
    this.recherche.set(texte);
    clearTimeout(this.minuterie);
    this.abonnement?.unsubscribe();
    if (texte.trim().length < 2) {
      this.resultats.set(null);
      this.rechercheEnCours.set(false);
      return;
    }
    this.rechercheEnCours.set(true);
    this.minuterie = setTimeout(() => {
      this.abonnement = this.caisse.rechercher(texte.trim()).subscribe({
        next: (r) => {
          this.resultats.set(r);
          this.rechercheEnCours.set(false);
        },
        error: () => {
          this.resultats.set([]);
          this.rechercheEnCours.set(false);
        },
      });
    }, 250);
  }

  /** Matricule exact -> inscription de l'annee -> formulaire (adresse mise a jour). */
  async continuerMatricule(): Promise<void> {
    const matricule = this.matriculeSaisi().trim().toUpperCase();
    if (!matricule) {
      this.erreurMatricule.set("Saisissez le matricule de l'élève.");
      return;
    }
    this.rechercheEnCours.set(true);
    const id = await this.actions.trouverInscription(matricule);
    this.rechercheEnCours.set(false);
    if (!id) {
      this.erreurMatricule.set(`Aucun élève inscrit cette année avec le matricule ${matricule}.`);
      return;
    }
    this.router.navigate([], { queryParams: { inscription: id }, replaceUrl: true });
    this.choisir(id);
  }

  choisir(inscriptionId: number): void {
    this.chargement.set(true);
    this.resultat.set(null);
    this.caisse.situation(inscriptionId).subscribe({
      next: (s) => this.afficher(s),
      error: (e: HttpErrorResponse) => {
        this.chargement.set(false);
        this.notifier(e.error?.message || 'Situation introuvable.', true);
      },
    });
  }

  /** Autre eleve : retour a la recherche. */
  changerEleve(): void {
    this.situation.set(null);
    this.resultat.set(null);
    this.matriculeSaisi.set('');
    this.erreurMatricule.set(null);
    this.router.navigate([], { queryParams: {}, replaceUrl: true });
  }

  // ================================================================ Saisie

  solder(): void {
    this.montant.set(this.situation()?.reste ?? 0);
    this.effacerErreur('montant');
  }

  minimum(): void {
    this.montant.set(this.situation()?.bornes?.minimum ?? 0);
    this.effacerErreur('montant');
  }

  quantite(id: number, defaut: number): number {
    return this.remises()[id] ?? defaut;
  }

  majRemise(id: number, valeur: number, maximum: number): void {
    this.remises.update((r) => ({ ...r, [id]: Math.max(0, Math.min(maximum, Math.round(valeur || 0))) }));
  }

  /** Controles du guichet (l'API revalide tout). */
  private verifier(): boolean {
    const s = this.situation()!;
    const b = s.bornes;
    const m = this.montant() ?? 0;
    const d = this.montantDette() ?? 0;
    const erreurs: Record<string, string> = {};
    if (m <= 0 && d <= 0) {
      erreurs['montant'] = 'Saisissez le montant versé.';
    } else if (m > 0 && b) {
      if (m > b.maximum) erreurs['montant'] = `Le montant dépasse le reste à payer (${this.f(b.maximum)}).`;
      else if (b.doit_solder && m !== b.maximum) erreurs['montant'] = `Dernier versement autorisé : il doit solder ${this.f(b.maximum)}.`;
      else if (m < b.minimum) erreurs['montant'] = `${b.numero === 1 ? 'Premier versement' : 'Versement'} minimum : ${this.f(b.minimum)}.`;
    }
    if (d > this.totalDettes()) {
      erreurs['montant_dette'] = `Le montant dépasse le reste des dettes (${this.f(this.totalDettes())}).`;
    }
    this.erreurs.set(erreurs);
    return !Object.keys(erreurs).length;
  }

  async encaisser(): Promise<void> {
    const s = this.situation();
    if (!s || this.enregistrement() || !this.verifier()) {
      return;
    }
    const alerte = await this.alertes.create({
      header: `Encaisser ${this.f(this.totalVerse())} ?`,
      message: `${s.eleve.nom} ${s.eleve.prenoms} · ${this.libellesMode[this.mode()]}${this.montantDette() ? ` (dont ${this.f(this.montantDette()!)} de dette)` : ''}.`,
      cssClass: 'alerte-me',
      buttons: [
        { text: 'Retour', role: 'cancel' },
        { text: 'Encaisser', role: 'confirm' },
      ],
    });
    await alerte.present();
    if ((await alerte.onDidDismiss()).role !== 'confirm') {
      return;
    }
    this.enregistrement.set(true);
    this.caisse
      .encaisser(s.id, {
        montant: this.montant() ?? 0,
        montant_dette: this.montantDette() ?? 0,
        mode_paiement: this.mode(),
        // Aujourd'hui : l'API prend sa date et son heure (fuseau du serveur).
        date_paiement: this.datePaiement() && this.datePaiement() !== this.maxDate ? this.datePaiement() : null,
        date_expiration: this.dateExpiration() || null,
        fournitures: s.fournitures.map((f) => ({ id: f.id, quantite_remise: this.quantite(f.id, f.quantite_remise) })),
      })
      .subscribe({
        next: (r) => {
          this.enregistrement.set(false);
          this.resultat.set(r);
          this.afficher(r.situation, false);
          (document.querySelector('ion-content.page-caisse') as HTMLIonContentElement | null)?.scrollToTop(200);
        },
        error: (e: HttpErrorResponse) => {
          this.enregistrement.set(false);
          const brutes = (e.error?.errors ?? {}) as Record<string, string[]>;
          const erreurs = Object.fromEntries(Object.entries(brutes).map(([k, v]) => [k, v[0]]));
          this.erreurs.set(erreurs);
          this.notifier(Object.values(erreurs)[0] || e.error?.message || 'Encaissement impossible. Vérifiez votre connexion puis réessayez.', true);
        },
      });
  }

  async imprimer(id: number): Promise<void> {
    if (!(await this.caisse.ouvrirRecu(id))) {
      this.notifier('Impossible d\'ouvrir le reçu. Autorisez les fenêtres (pop-up) puis réessayez.', true);
    }
  }

  /** Nouveau versement pour le meme eleve (apres un encaissement). */
  nouveauVersement(): void {
    this.resultat.set(null);
  }

  // ================================================================ Affichage

  f(valeur: number | null | undefined): string {
    return (valeur ?? 0).toLocaleString('fr-FR') + ' F';
  }

  dateFr(date: string | null | undefined): string {
    return date ? date.slice(0, 10).split('-').reverse().join('/') : '—';
  }

  heure(date: string): string {
    return date.slice(11, 16);
  }

  initiales(nom: string, prenoms: string): string {
    return ((nom?.charAt(0) ?? '') + (prenoms?.charAt(0) ?? '')).toUpperCase();
  }

  versement(n: number | null): string {
    return n ? (n === 1 ? '1er versement' : `${n}e versement`) : 'Dette';
  }

  valeurNombre(evenement: Event): number | null {
    const brut = (evenement.target as HTMLInputElement).value.replace(/\s/g, '');
    return brut === '' || isNaN(Number(brut)) ? null : Math.round(Number(brut));
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  effacerErreur(cle: string): void {
    if (this.erreurs()[cle]) {
      const { [cle]: _, ...reste } = this.erreurs();
      this.erreurs.set(reste);
    }
  }

  private afficher(s: SituationCaisse, reinitialiser = true): void {
    this.situation.set(s);
    this.chargement.set(false);
    this.montant.set(null);
    this.montantDette.set(null);
    this.remises.set({});
    this.erreurs.set({});
    if (reinitialiser) {
      this.mode.set('especes');
      this.datePaiement.set(this.aujourdhui());
      this.dateExpiration.set(null);
    }
  }

  private aujourdhui(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3500, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}

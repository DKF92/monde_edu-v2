import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, ToastController, IonContent, IonIcon, IonModal, IonSpinner, IonSkeletonText } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  createOutline,
  printOutline,
  closeCircleOutline,
  chevronForwardOutline,
  swapHorizontalOutline,
  schoolOutline,
  cameraOutline,
  globeOutline,
  checkmarkCircle,
  checkmarkCircleOutline,
  ellipseOutline,
  closeOutline,
  cloudOfflineOutline,
  informationCircleOutline,
  alertCircleOutline,
  warningOutline,
  cubeOutline,
  cashOutline,
  callOutline,
  personOutline,
} from 'ionicons/icons';
import { InscriptionService } from '../../core/services/inscription.service';
import { AuthService } from '../../core/services/auth.service';
import {
  ClasseInscription,
  DetailInscription,
  LIBELLES_DECISION,
  LIBELLES_STATUT,
  OptionsInscription,
  ResultatEnLigne,
  TONS_STATUT,
} from '../../core/models/inscription.model';
import { imprimerFicheInscription } from './fiche-inscription';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { RecuEnLigneComponent } from './recu-en-ligne.component';
import { calculerEcarts } from './en-ligne';
import { catchError, of } from 'rxjs';

/** Fiche d'une inscription : eleve, scolarite, frais, fournitures, actions. */
@Component({
  selector: 'app-inscription-detail',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSpinner, IonSkeletonText, SelecteurComponent, RecuEnLigneComponent],
  templateUrl: './inscription-detail.page.html',
  styleUrl: './inscription-detail.page.scss',
})
export class InscriptionDetailPage implements OnInit {
  private readonly service = inject(InscriptionService);
  private readonly route = inject(ActivatedRoute);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);
  private readonly auth = inject(AuthService);
  readonly peutEncaisser = computed(() => this.auth.aPermission('reglements.encaisser'));
  readonly router = inject(Router);

  readonly libellesStatut = LIBELLES_STATUT;
  readonly tons = TONS_STATUT;
  readonly libellesDecision = LIBELLES_DECISION;
  readonly liens: Record<string, string> = { pere: 'Père', mere: 'Mère', tuteur_legal: 'Tuteur' };

  readonly detail = signal<DetailInscription | null>(null);
  readonly options = signal<OptionsInscription | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal(false);
  readonly action = signal<string | null>(null);
  /** Arrivee juste apres l'enregistrement d'une nouvelle inscription. */
  readonly nouvelle = signal(false);

  readonly modaleClasse = signal(false);
  readonly classeChoisie = signal<number | null>(null);

  /** Mise a jour depuis l'inscription en ligne (inscription jamais verifiee). */
  readonly modaleEnLigne = signal(false);
  readonly rechercheEnLigne = signal(false);
  readonly enLigne = signal<ResultatEnLigne | null>(null);
  readonly ecartsChoisis = signal<Set<string>>(new Set());

  /** Differences avec notre fiche (statut affecte : seulement sans paiement). */
  readonly ecarts = computed(() => {
    const d = this.detail();
    const recu = this.enLigne()?.donnees;
    return d && recu ? calculerEcarts(recu, d.eleve, d.modifiable_tarif ? d.affecte : null) : [];
  });

  /** Quantites remises saisies (fournitures en nature). */
  readonly remises = signal<Record<number, number>>({});

  readonly classesDuNiveau = computed<ClasseInscription[]>(() => {
    const d = this.detail();
    return this.options()?.niveaux.find((n) => n.id === d?.niveau?.id)?.classes ?? [];
  });

  /** Liste deroulante : classes du niveau de l'eleve uniquement. */
  readonly optionsClasses = computed<OptionSelecteur[]>(() =>
    this.classesDuNiveau().map((c) => ({
      valeur: c.id,
      libelle: c.libelle,
      detail: `${c.effectif}${c.limite ? ' / ' + c.limite : ''} élève${c.effectif > 1 ? 's' : ''} · ${c.garcons} G · ${c.filles} F`,
      badge: c.id === this.detail()?.classe?.id ? { texte: 'Actuelle', ton: 'succes' as const } : this.estComplete(c) ? { texte: 'Complète', ton: 'attention' as const } : null,
      desactivee: this.estComplete(c),
    })),
  );

  readonly classeSelectionnee = computed(() => this.classesDuNiveau().find((c) => c.id === this.classeChoisie()) ?? null);

  /** Evite de recharger au tout premier affichage (deja fait par ngOnInit). */
  private premierAffichage = true;

  readonly fournituresModifiees = computed(() => {
    const d = this.detail();
    return !!d && d.fournitures.some((f) => (this.remises()[f.id] ?? f.quantite_remise) !== f.quantite_remise);
  });

  readonly totalDettes = computed(() => (this.detail()?.dettes ?? []).reduce((s, d) => s + d.reste, 0));

  constructor() {
    addIcons({
      chevronBackOutline,
      createOutline,
      printOutline,
      closeCircleOutline,
      chevronForwardOutline,
      swapHorizontalOutline,
      schoolOutline,
      cameraOutline,
      globeOutline,
      checkmarkCircle,
      checkmarkCircleOutline,
      ellipseOutline,
      closeOutline,
      cloudOfflineOutline,
      informationCircleOutline,
      alertCircleOutline,
      warningOutline,
      cubeOutline,
      cashOutline,
      callOutline,
      personOutline,
    });
  }

  ngOnInit(): void {
    this.nouvelle.set(!!history.state?.nouvelle);
    this.charger();
    this.service.options().subscribe({ next: (o) => this.options.set(o) });
  }

  /** Retour sur la page (apres une modification) : donnees a jour sans F5. */
  ionViewWillEnter(): void {
    if (this.premierAffichage) {
      this.premierAffichage = false;
      return;
    }
    this.charger();
  }

  charger(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    this.erreur.set(false);
    this.service.detail(id).subscribe({
      next: (d) => this.afficher(d),
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  modifier(): void {
    this.router.navigate(['/tabs/inscriptions', this.detail()!.id, 'modifier']);
  }

  imprimer(): void {
    if (!imprimerFicheInscription(this.detail()!)) {
      this.notifier('Autorisez les fenêtres (pop-up) pour imprimer la fiche.', true);
    }
  }

  // ------------------------------------------------ Classe

  ouvrirClasses(): void {
    this.classeChoisie.set(this.detail()?.classe?.id ?? null);
    this.service.options().subscribe({ next: (o) => this.options.set(o) });
    this.modaleClasse.set(true);
  }

  estComplete(c: ClasseInscription): boolean {
    return c.complete && c.id !== this.detail()?.classe?.id;
  }

  validerClasse(): void {
    const d = this.detail();
    const id = this.classeChoisie();
    if (!d || !id || id === d.classe?.id) {
      this.modaleClasse.set(false);
      return;
    }
    this.action.set('classe');
    this.service.changerClasse(d.id, id).subscribe({
      next: (maj) => {
        this.modaleClasse.set(false);
        this.afficher(maj);
        this.notifier(d.classe ? `Classe changée : ${maj.classe?.libelle}.` : `Élève placé(e) en ${maj.classe?.libelle}.`);
      },
      error: (e: HttpErrorResponse) => this.echec(e),
    });
  }

  // ------------------------------------------------ Fournitures

  quantite(id: number, defaut: number): number {
    return this.remises()[id] ?? defaut;
  }

  majRemise(id: number, valeur: number, maximum: number): void {
    this.remises.update((r) => ({ ...r, [id]: Math.max(0, Math.min(maximum, Math.round(valeur || 0))) }));
  }

  enregistrerFournitures(): void {
    const d = this.detail();
    if (!d) {
      return;
    }
    this.action.set('fournitures');
    const fournitures = d.fournitures.map((f) => ({ id: f.id, quantite_remise: this.quantite(f.id, f.quantite_remise) }));
    this.service.enregistrerFournitures(d.id, fournitures).subscribe({
      next: (maj) => {
        this.afficher(maj);
        this.notifier('Fournitures enregistrées.');
      },
      error: (e: HttpErrorResponse) => this.echec(e),
    });
  }

  // ------------------------------------------------ Photo

  choisirPhoto(evenement: Event): void {
    const fichier = (evenement.target as HTMLInputElement).files?.[0];
    (evenement.target as HTMLInputElement).value = '';
    const d = this.detail();
    if (!fichier || !d) {
      return;
    }
    this.action.set('photo');
    this.service.envoyerPhoto(d.id, fichier).subscribe({
      next: (maj) => {
        this.afficher(maj);
        this.notifier('Photo enregistrée.');
      },
      error: (e: HttpErrorResponse) => this.echec(e),
    });
  }

  // ------------------------------------------------ Inscription en ligne

  ouvrirEnLigne(): void {
    this.modaleEnLigne.set(true);
    this.verifierEnLigne();
  }

  verifierEnLigne(): void {
    this.rechercheEnLigne.set(true);
    this.enLigne.set(null);
    this.service
      .enLigne(this.detail()!.eleve.matricule)
      .pipe(catchError(() => of<ResultatEnLigne>({ trouve: false, erreur: 'Vérification impossible pour le moment.' })))
      .subscribe((r) => {
        this.rechercheEnLigne.set(false);
        this.enLigne.set(r);
        // Toutes les differences cochees par defaut (comme a l'inscription).
        this.ecartsChoisis.set(new Set(this.ecarts().map((e) => e.cle)));
      });
  }

  basculerEcart(cle: string): void {
    const choix = new Set(this.ecartsChoisis());
    if (choix.has(cle)) {
      choix.delete(cle);
    } else {
      choix.add(cle);
    }
    this.ecartsChoisis.set(choix);
  }

  /** Suite dans la page de modification, pre-remplie avec le recu. */
  continuerEnLigne(): void {
    this.modaleEnLigne.set(false);
    this.router.navigate(['/tabs/inscriptions', this.detail()!.id, 'modifier'], {
      state: { enLigne: this.enLigne(), champs: [...this.ecartsChoisis()] },
    });
  }

  // ------------------------------------------------ Annulation (= suppression, V1)

  async annuler(): Promise<void> {
    const d = this.detail()!;
    const alerte = await this.alertes.create({
      header: 'Annuler cette inscription ?',
      message: `L'inscription ${d.annee} de ${d.eleve.nom} ${d.eleve.prenoms} sera supprimée avec ses frais. Sa fiche est conservée : vous pourrez la reprendre avec « Nouvelle inscription ».`,
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
    this.action.set('annuler');
    this.service.annuler(d.id).subscribe({
      next: () => {
        this.action.set(null);
        this.notifier('Inscription annulée.');
        this.router.navigateByUrl('/tabs/inscriptions', { replaceUrl: true });
      },
      error: (e: HttpErrorResponse) => this.echec(e),
    });
  }

  // ------------------------------------------------ Affichage

  montant(valeur: number | null | undefined): string {
    return (valeur ?? 0).toLocaleString('fr-FR') + ' F';
  }

  dateFr(date: string | null | undefined): string {
    return date ? date.slice(0, 10).split('-').reverse().join('/') : '—';
  }

  initiales(d: DetailInscription): string {
    return ((d.eleve.nom?.charAt(0) ?? '') + (d.eleve.prenoms?.charAt(0) ?? '')).toUpperCase();
  }

  provenance(d: DetailInscription): string {
    return [
      d.etablissement_origine,
      d.classe_origine,
      d.decision_origine ? LIBELLES_DECISION[d.decision_origine] ?? d.decision_origine : null,
      d.moyenne_origine ? d.moyenne_origine + '/20' : null,
    ]
      .filter(Boolean)
      .join(' · ');
  }

  remplissage(c: ClasseInscription): number {
    return c.limite ? Math.min(100, Math.round((c.effectif / c.limite) * 100)) : 0;
  }

  valeurNombre(evenement: Event): number {
    return Number((evenement.target as HTMLInputElement).value) || 0;
  }

  private afficher(d: DetailInscription): void {
    this.detail.set(d);
    this.remises.set({});
    this.chargement.set(false);
    this.action.set(null);
  }

  private echec(e: HttpErrorResponse): void {
    this.action.set(null);
    const erreurs = e.error?.errors as Record<string, string[]> | undefined;
    this.notifier((erreurs && Object.values(erreurs)[0]?.[0]) || e.error?.message || 'Action impossible. Vérifiez votre connexion puis réessayez.', true);
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}

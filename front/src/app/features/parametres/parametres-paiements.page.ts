import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { NgTemplateOutlet } from '@angular/common';
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
  informationCircleOutline,
  cloudOfflineOutline,
  cashOutline,
  pricetagOutline,
  receiptOutline,
  addOutline,
  createOutline,
  powerOutline,
  refreshOutline,
  closeOutline,
  checkmarkOutline,
  checkbox,
  squareOutline,
  radioButtonOn,
  radioButtonOffOutline,
  alertCircleOutline,
  layersOutline,
} from 'ionicons/icons';
import { ParametreService } from '../../core/services/parametre.service';
import { GrilleColonne, TarifsNiveaux, TypeFrais, TypeReduction, TypeReductionSaisie } from '../../core/models/parametre.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE, paginer } from '../../shared/pagination.component';

type Onglet = 'montants' | 'reductions' | 'frais';
type Colonne = 'affecte' | 'non_affecte';

/**
 * Parametres des paiements, iso V1 (pages/CoutFormation) :
 * - montants d'inscription par niveau, colonnes eleve affecte / non affecte,
 *   montant minimum a verser a l'inscription ; un affecte ne paie pas de
 *   scolarite ;
 * - types de reductions (V1 "reduction" et "cas") ;
 * - types de frais (colonnes cout_* de la V1, extensibles).
 */
@Component({
  selector: 'app-parametres-paiements',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, SelecteurComponent, NgTemplateOutlet, PaginationComponent],
  templateUrl: './parametres-paiements.page.html',
  styleUrls: ['./parametres.commun.scss', './parametres-paiements.page.scss'],
})
export class ParametresPaiementsPage implements OnInit {
  private readonly service = inject(ParametreService);
  private readonly toasts = inject(ToastController);
  private readonly alertes = inject(AlertController);
  readonly router = inject(Router);

  readonly onglet = signal<Onglet>('montants');
  readonly colonnes: Colonne[] = ['affecte', 'non_affecte'];

  // Pagination des listes (recapitulatif, reductions, types de frais).
  readonly pageRecap = signal(1);
  readonly tailleRecap = signal(TAILLES_PAGE[0]);
  readonly recapPage = computed(() => paginer(this.tarifs()?.niveaux ?? [], this.pageRecap(), this.tailleRecap()));
  readonly pageReductions = signal(1);
  readonly tailleReductions = signal(TAILLES_PAGE[0]);
  readonly reductionsPage = computed(() => paginer(this.typesReductions(), this.pageReductions(), this.tailleReductions()));
  readonly pageFrais = signal(1);
  readonly tailleFrais = signal(TAILLES_PAGE[0]);
  readonly fraisPage = computed(() => paginer(this.typesFrais(), this.pageFrais(), this.tailleFrais()));
  readonly chargement = signal(true);
  readonly erreur = signal(false);

  // ------------------------------------------------------------ Montants
  readonly tarifs = signal<TarifsNiveaux | null>(null);
  readonly niveauId = signal<number | null>(null);
  /** Saisie en cours : colonne -> type de frais (ou "minimum") -> texte. */
  readonly saisie = signal<Record<Colonne, Record<string, string>>>({ affecte: {}, non_affecte: {} });
  /** Frais annexes identiques pour affectes et non affectes (comme en V1). */
  readonly annexesIdentiques = signal(true);
  readonly enregistrementTarif = signal(false);

  // ------------------------------------------------------------ Reductions
  readonly typesReductions = signal<TypeReduction[]>([]);
  readonly reductionOuverte = signal(false);
  readonly reductionEditee = signal<TypeReduction | null>(null);
  readonly formReduction = signal<TypeReductionSaisie>(this.reductionVide());
  readonly plafondTexte = signal('');
  readonly minimumTexte = signal('0');
  readonly erreursReduction = signal<Record<string, string>>({});
  readonly enregistrementReduction = signal(false);
  readonly actionReduction = signal<number | null>(null);

  // ------------------------------------------------------------ Types de frais
  readonly nouveauFrais = signal('');
  readonly natureNouveauFrais = signal<'annexe' | 'en_nature'>('annexe');
  readonly erreurFrais = signal<string | null>(null);
  readonly ajoutFrais = signal(false);
  readonly fraisEdite = signal<number | null>(null);
  readonly libelleEdite = signal('');

  readonly typesFrais = computed(() => this.tarifs()?.types_frais ?? []);
  /** Montants d'inscription : seuls les types actifs sont affiches. */
  readonly typesActifs = computed(() => this.typesFrais().filter((t) => t.is_active));
  readonly fraisPrincipaux = computed(() => this.typesActifs().filter((t) => t.nature === 'inscription' || t.nature === 'scolarite'));
  readonly fraisAnnexes = computed(() => this.typesActifs().filter((t) => t.nature === 'annexe'));
  /** Fournitures apportees par l'eleve (V1 craie / rame) : une quantite, pas un montant. */
  readonly fraisEnNature = computed(() => this.typesActifs().filter((t) => t.nature === 'en_nature'));
  /** Types comptes en argent (total, minimum). */
  readonly typesEnArgent = computed(() => this.typesActifs().filter((t) => t.nature !== 'en_nature'));
  /** Type de frais dont un reglage est en cours d'enregistrement. */
  readonly fraisEnCours = signal<number | null>(null);

  readonly optionsNiveaux = computed<OptionSelecteur[]>(() =>
    (this.tarifs()?.niveaux ?? []).map((n) => ({
      valeur: n.id,
      libelle: n.libelle,
      badge: n.affecte || n.non_affecte ? { texte: 'Saisi', ton: 'succes' as const } : { texte: 'À saisir', ton: 'neutre' as const },
    })),
  );

  readonly niveau = computed(() => this.tarifs()?.niveaux.find((n) => n.id === this.niveauId()) ?? null);

  readonly modifie = computed(() => {
    const n = this.niveau();
    if (!n) {
      return false;
    }
    return (['affecte', 'non_affecte'] as Colonne[]).some((col) => {
      const grille = n[col];
      const s = this.saisie()[col];
      return this.typesActifs().some(
        (t) => !this.bloque(col, t) && this.nombre(s[t.id]) !== (grille?.montants[t.id] ?? 0),
      );
    });
  });

  readonly tarifInvalide = computed(() =>
    (['affecte', 'non_affecte'] as Colonne[]).some((col) => Object.values(this.saisie()[col]).some((v) => !this.valide(v))),
  );

  constructor() {
    addIcons({
      chevronBackOutline,
      informationCircleOutline,
      cloudOfflineOutline,
      cashOutline,
      pricetagOutline,
      receiptOutline,
      addOutline,
      createOutline,
      powerOutline,
      refreshOutline,
      closeOutline,
      checkmarkOutline,
      checkbox,
      squareOutline,
      radioButtonOn,
      radioButtonOffOutline,
      alertCircleOutline,
      layersOutline,
    });
  }

  ngOnInit(): void {
    this.charger();
  }

  charger(): void {
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.tarifs().subscribe({
      next: (t) => {
        this.tarifs.set(t);
        this.choisirNiveau(this.niveauId() ?? t.niveaux[0]?.id ?? null, true);
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
    this.service.typesReductions().subscribe({ next: (liste) => this.typesReductions.set(liste) });
  }

  // ================================================================ Montants

  async changerNiveau(id: number): Promise<void> {
    if (this.modifie()) {
      const alerte = await this.alertes.create({
        header: 'Modifications non enregistrées',
        message: `Les montants saisis pour ${this.niveau()?.libelle} seront perdus.`,
        cssClass: 'alerte-me',
        buttons: [
          { text: 'Rester', role: 'cancel' },
          { text: 'Changer de niveau', role: 'confirm', cssClass: 'bouton-danger' },
        ],
      });
      await alerte.present();
      if ((await alerte.onDidDismiss()).role !== 'confirm') {
        return;
      }
    }
    this.choisirNiveau(id, true);
  }

  choisirNiveau(id: number | null, reinitialiser: boolean): void {
    this.niveauId.set(id);
    if (!reinitialiser) {
      return;
    }
    const n = this.tarifs()?.niveaux.find((x) => x.id === id);
    const colonne = (grille: GrilleColonne | null): Record<string, string> => {
      const valeurs: Record<string, string> = {};
      this.typesFrais().forEach((t) => (valeurs[t.id] = grille?.montants[t.id] ? String(grille.montants[t.id]) : ''));
      return valeurs;
    };
    const saisie = { affecte: colonne(n?.affecte ?? null), non_affecte: colonne(n?.non_affecte ?? null) };
    this.saisie.set(saisie);
    this.annexesIdentiques.set(this.fraisAnnexes().every((t) => (saisie.affecte[t.id] || '') === (saisie.non_affecte[t.id] || '')));
  }

  valeur(col: Colonne, cle: string | number): string {
    return this.saisie()[col][cle] ?? '';
  }

  saisir(col: Colonne, type: TypeFrais, valeur: string): void {
    const cle = String(type.id);
    const autre: Colonne = col === 'affecte' ? 'non_affecte' : 'affecte';
    const miroir = type.nature === 'annexe' && this.annexesIdentiques() && !this.bloque(autre, type);
    this.saisie.update((s) => {
      const suivant = { affecte: { ...s.affecte }, non_affecte: { ...s.non_affecte } };
      suivant[col][cle] = valeur;
      if (miroir) {
        suivant[autre][cle] = valeur;
      }
      return suivant;
    });
  }

  basculerAnnexesIdentiques(): void {
    const identiques = !this.annexesIdentiques();
    this.annexesIdentiques.set(identiques);
    if (identiques) {
      // Aligne les affectes sur les non affectes.
      this.saisie.update((s) => {
        const affecte = { ...s.affecte };
        this.fraisAnnexes().forEach((t) => (affecte[t.id] = s.non_affecte[t.id] ?? ''));
        return { affecte, non_affecte: s.non_affecte };
      });
    }
  }

  /** Frais qui ne concerne pas ce type d'eleve (reglage de l'onglet Types de
   * frais, ex : scolarite d'un eleve affecte) : champ grise. */
  bloque(col: Colonne, type: TypeFrais): boolean {
    return col === 'affecte' ? !type.applicable_affecte : !type.applicable_non_affecte;
  }

  total(col: Colonne): number {
    return this.typesEnArgent().reduce((somme, t) => somme + (this.bloque(col, t) ? 0 : this.nombre(this.saisie()[col][t.id])), 0);
  }

  /**
   * Minimum a verser a l'inscription, calcule comme le serveur :
   * affecte = total des annexes ; non affecte = annexes + frais d'inscription.
   */
  minimum(col: Colonne): number {
    return this.typesActifs()
      .filter((t) => !this.bloque(col, t) && (t.nature === 'annexe' || (col === 'non_affecte' && t.nature === 'inscription')))
      .reduce((somme, t) => somme + this.nombre(this.saisie()[col][t.id]), 0);
  }

  totalGrille(grille: GrilleColonne | null, col: Colonne): number | null {
    return grille
      ? this.typesEnArgent().reduce((somme, t) => somme + (this.bloque(col, t) ? 0 : grille.montants[t.id] ?? 0), 0)
      : null;
  }

  annulerTarif(): void {
    this.choisirNiveau(this.niveauId(), true);
  }

  enregistrerTarif(): void {
    const id = this.niveauId();
    if (!id || this.tarifInvalide()) {
      return;
    }
    const colonne = (col: Colonne) => ({
      montants: Object.fromEntries(this.typesActifs().map((t) => [t.id, this.bloque(col, t) ? 0 : this.nombre(this.saisie()[col][t.id])])),
    });
    this.enregistrementTarif.set(true);
    this.service.enregistrerTarif(id, { affecte: colonne('affecte'), non_affecte: colonne('non_affecte') }).subscribe({
      next: (t) => {
        this.enregistrementTarif.set(false);
        this.tarifs.set(t);
        this.choisirNiveau(id, true);
        this.notifier(`Montants ${this.niveau()?.libelle} enregistrés.`);
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrementTarif.set(false);
        this.notifier(this.premiereErreur(e), true);
      },
    });
  }

  // ================================================================ Reductions

  nouvelleReduction(): void {
    this.reductionEditee.set(null);
    this.formReduction.set(this.reductionVide());
    this.minimumTexte.set('0');
    this.plafondTexte.set('');
    this.erreursReduction.set({});
    this.reductionOuverte.set(true);
  }

  modifierReduction(t: TypeReduction): void {
    this.reductionEditee.set(t);
    const { id, code, ...saisie } = t;
    this.formReduction.set(saisie);
    this.minimumTexte.set(String(t.montant_minimum));
    this.plafondTexte.set(t.plafond_pourcentage?.toString() ?? '');
    this.erreursReduction.set({});
    this.reductionOuverte.set(true);
  }

  majReduction(modif: Partial<TypeReductionSaisie>): void {
    this.formReduction.update((f) => ({ ...f, ...modif }));
    Object.keys(modif).forEach((cle) => this.effacerErreurReduction(cle));
  }

  effacerErreurReduction(cle: string): void {
    if (this.erreursReduction()[cle]) {
      const { [cle]: _, ...reste } = this.erreursReduction();
      this.erreursReduction.set(reste);
    }
  }

  enregistrerReduction(): void {
    const form = this.formReduction();
    const erreurs: Record<string, string> = {};
    if (!form.libelle.trim()) {
      erreurs['libelle'] = 'Le libellé est obligatoire.';
    }
    if (!this.valide(this.minimumTexte())) {
      erreurs['montant_minimum'] = 'Montant entier positif attendu.';
    }
    const plafond = this.plafondTexte().trim() ? Number(this.plafondTexte()) : null;
    if (plafond !== null && (!Number.isInteger(plafond) || plafond < 1 || plafond > 100)) {
      erreurs['plafond_pourcentage'] = 'Pourcentage entre 1 et 100 (vide = pas de plafond).';
    }
    if (Object.keys(erreurs).length) {
      this.erreursReduction.set(erreurs);
      return;
    }

    const saisie: TypeReductionSaisie = {
      ...form,
      libelle: form.libelle.trim(),
      montant_minimum: this.nombre(this.minimumTexte()),
      plafond_pourcentage: plafond,
    };
    const edite = this.reductionEditee();
    this.enregistrementReduction.set(true);
    (edite ? this.service.modifierTypeReduction(edite.id, saisie) : this.service.creerTypeReduction(saisie)).subscribe({
      next: (t) => {
        this.enregistrementReduction.set(false);
        this.reductionOuverte.set(false);
        this.typesReductions.update((liste) => (edite ? liste.map((x) => (x.id === t.id ? t : x)) : [...liste, t]));
        this.notifier(edite ? 'Type de réduction modifié.' : 'Type de réduction créé.');
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrementReduction.set(false);
        if (e.status === 422 && e.error?.errors) {
          this.erreursReduction.set(
            Object.fromEntries(Object.entries(e.error.errors as Record<string, string[]>).map(([k, v]) => [k, v[0]])),
          );
        } else {
          this.notifier(this.premiereErreur(e), true);
        }
      },
    });
  }

  basculerReduction(t: TypeReduction): void {
    const { id, code, ...saisie } = t;
    this.actionReduction.set(t.id);
    this.service.modifierTypeReduction(t.id, { ...saisie, is_active: !t.is_active }).subscribe({
      next: (maj) => {
        this.actionReduction.set(null);
        this.typesReductions.update((liste) => liste.map((x) => (x.id === maj.id ? maj : x)));
        this.notifier(maj.is_active ? 'Type de réduction réactivé.' : 'Type de réduction désactivé.');
      },
      error: (e: HttpErrorResponse) => {
        this.actionReduction.set(null);
        this.notifier(this.premiereErreur(e), true);
      },
    });
  }

  // ================================================================ Types de frais

  ajouterFrais(): void {
    const libelle = this.nouveauFrais().trim();
    if (!libelle) {
      this.erreurFrais.set('Indiquez le nom du frais (ex : Cantine).');
      return;
    }
    this.ajoutFrais.set(true);
    this.service.creerTypeFrais(libelle, this.natureNouveauFrais()).subscribe({
      next: () => {
        this.ajoutFrais.set(false);
        this.nouveauFrais.set('');
        this.erreurFrais.set(null);
        this.notifier(`Frais « ${libelle} » ajouté.`);
        this.rechargerTarifs();
      },
      error: (e: HttpErrorResponse) => {
        this.ajoutFrais.set(false);
        this.erreurFrais.set(this.premiereErreur(e));
      },
    });
  }

  editerFrais(t: TypeFrais): void {
    this.fraisEdite.set(t.id);
    this.libelleEdite.set(t.libelle);
  }

  renommerFrais(t: TypeFrais): void {
    const libelle = this.libelleEdite().trim();
    if (!libelle || libelle === t.libelle) {
      this.fraisEdite.set(null);
      return;
    }
    this.service.modifierTypeFrais(t.id, { libelle }).subscribe({
      next: () => {
        this.fraisEdite.set(null);
        this.rechargerTarifs();
      },
      error: (e: HttpErrorResponse) => this.notifier(this.premiereErreur(e), true),
    });
  }

  /** 125000 -> "125 000" */
  montant(valeur: number | null | undefined): string {
    return new Intl.NumberFormat('fr-FR').format(valeur ?? 0);
  }

  /** Active / desactive un type, ou le rend applicable ou non a un type d'eleve. */
  reglerFrais(t: TypeFrais, modif: Partial<Pick<TypeFrais, 'is_active' | 'applicable_affecte' | 'applicable_non_affecte'>>): void {
    if (this.modifie()) {
      this.notifier("Enregistrez d'abord les montants en cours de saisie.", true);
      return;
    }
    this.fraisEnCours.set(t.id);
    this.service.modifierTypeFrais(t.id, modif).subscribe({
      next: (maj) => {
        this.fraisEnCours.set(null);
        if ('is_active' in modif) {
          this.notifier(
            maj.is_active
              ? `« ${maj.libelle} » activé.`
              : `« ${maj.libelle} » désactivé : il n'apparaît plus dans les montants d'inscription.`,
          );
        }
        this.rechargerTarifs();
      },
      error: (e: HttpErrorResponse) => {
        this.fraisEnCours.set(null);
        this.notifier(this.premiereErreur(e), true);
      },
    });
  }

  libelleColonne(col: Colonne): string {
    return col === 'affecte' ? 'affectés' : 'non affectés';
  }

  libelleNature(t: TypeFrais): string {
    return t.nature === 'inscription' ? 'Inscription' : t.nature === 'scolarite' ? 'Scolarité' : t.nature === 'dette' ? 'Dette' : t.nature === 'en_nature' ? 'En nature' : 'Annexe';
  }

  // ================================================================ Interne

  private rechargerTarifs(): void {
    const id = this.niveauId();
    this.service.tarifs().subscribe({
      next: (t) => {
        this.tarifs.set(t);
        this.choisirNiveau(id ?? t.niveaux[0]?.id ?? null, true);
      },
    });
  }

  private reductionVide(): TypeReductionSaisie {
    return {
      libelle: '',
      base: 'frais_principaux',
      montant_minimum: 0,
      minimum_frais_principaux: false,
      plafond_pourcentage: null,
      is_active: true,
    };
  }

  valide(valeur: string | undefined): boolean {
    if (!valeur?.trim()) {
      return true;
    }
    const n = Number(valeur);
    return Number.isInteger(n) && n >= 0 && n <= 100_000_000;
  }

  private nombre(valeur: string | undefined): number {
    return valeur?.trim() && this.valide(valeur) ? Number(valeur) : 0;
  }

  private premiereErreur(e: HttpErrorResponse): string {
    return (
      (e.error?.errors && (Object.values(e.error.errors)[0] as string[])[0]) ||
      (e.status === 403 ? "Vous n'avez pas le droit de modifier ces paramètres." : null) ||
      'Enregistrement impossible. Vérifiez votre connexion puis réessayez.'
    );
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}

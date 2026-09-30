import { Component, EventEmitter, Input, OnChanges, Output, SimpleChanges, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { IonModal, IonIcon, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  closeOutline,
  personOutline,
  briefcaseOutline,
  schoolOutline,
  checkmarkCircle,
  ellipseOutline,
  lockClosedOutline,
  alertCircleOutline,
  lockClosed,
  layersOutline,
  bookOutline,
} from 'ionicons/icons';
import { UtilisateurService } from '../../core/services/utilisateur.service';
import {
  OptionsUtilisateurs,
  PosteOption,
  StatutContrat,
  Utilisateur,
  UtilisateurAvecMotDePasse,
  UtilisateurSaisie,
} from '../../core/models/utilisateur.model';
import { OptionMultiple, SelecteurMultipleComponent } from '../../shared/selecteur-multiple.component';

/**
 * Creation / modification d'un utilisateur (fiche V1 "administration") :
 * identite, postes dans l'etablissement, niveaux (educateur) ou matieres
 * (professeur) selon les postes choisis, informations professionnelles.
 */
@Component({
  selector: 'app-utilisateur-formulaire',
  standalone: true,
  imports: [ReactiveFormsModule, IonModal, IonIcon, IonSpinner, SelecteurMultipleComponent],
  templateUrl: './utilisateur-formulaire.component.html',
  styleUrl: './utilisateur-formulaire.component.scss',
})
export class UtilisateurFormulaireComponent implements OnChanges {
  private readonly fb = inject(FormBuilder);
  private readonly service = inject(UtilisateurService);

  @Input() isOpen = false;
  /** null = creation. */
  @Input() utilisateur: Utilisateur | null = null;
  /** Postes, niveaux et matieres de l'etablissement. */
  @Input() options: OptionsUtilisateurs | null = null;
  @Output() ferme = new EventEmitter<void>();
  /** Modification enregistree. */
  @Output() modifie = new EventEmitter<Utilisateur>();
  /** Compte cree : le mot de passe provisoire est a afficher une seule fois. */
  @Output() cree = new EventEmitter<UtilisateurAvecMotDePasse>();

  readonly contrats: { valeur: StatutContrat; libelle: string }[] = [
    { valeur: 'permanent', libelle: 'Permanent' },
    { valeur: 'vacataire', libelle: 'Vacataire' },
    { valeur: 'stagiaire', libelle: 'Stagiaire' },
  ];

  readonly postesChoisis = signal<Set<number>>(new Set());
  readonly niveauxChoisis = signal<number[]>([]);
  readonly matieresChoisies = signal<number[]>([]);
  /** Copie signal des postes (pour les computed ci-dessous). */
  private readonly postesSignal = signal<PosteOption[]>([]);

  /** Champ Niveaux si un poste choisi est rattache a des niveaux (educateur). */
  readonly afficherNiveaux = computed(() =>
    this.postesSignal().some((p) => p.lie_niveaux && this.postesChoisis().has(p.id)),
  );
  /** Champ Matieres si un poste choisi enseigne des matieres (professeur). */
  readonly afficherMatieres = computed(() =>
    this.postesSignal().some((p) => p.lie_matieres && this.postesChoisis().has(p.id)),
  );
  readonly enregistrement = signal(false);
  readonly erreurGenerale = signal<string | null>(null);
  /** Erreurs de validation renvoyees par l'API, par champ. */
  readonly erreursServeur = signal<Record<string, string>>({});
  readonly tentative = signal(false);

  readonly formulaire = this.fb.group({
    nom: ['', [Validators.required, Validators.maxLength(80)]],
    prenoms: ['', [Validators.maxLength(180)]],
    email: ['', [Validators.required, Validators.email, Validators.maxLength(150)]],
    telephone: ['', [Validators.maxLength(20)]],
    sexe: [null as 'M' | 'F' | null],
    statut_contrat: [null as StatutContrat | null],
    diplome: ['', [Validators.maxLength(100)]],
    date_embauche: [''],
    nationalite: ['', [Validators.maxLength(60)]],
    quartier: ['', [Validators.maxLength(150)]],
  });

  constructor() {
    addIcons({
      closeOutline,
      personOutline,
      briefcaseOutline,
      schoolOutline,
      checkmarkCircle,
      ellipseOutline,
      lockClosedOutline,
      alertCircleOutline,
      lockClosed,
      layersOutline,
      bookOutline,
    });
  }

  get estCreation(): boolean {
    return !this.utilisateur;
  }

  /** Ses propres postes ne se modifient pas (c'est a un autre administrateur). */
  get postesVerrouilles(): boolean {
    return !!this.utilisateur?.est_moi;
  }

  /** Postes actifs, plus les postes desactives que le compte a encore. */
  get postesAffiches(): PosteOption[] {
    return (this.options?.postes ?? []).filter((p) => p.is_active || this.postesChoisis().has(p.id));
  }

  get aDesPostesProteges(): boolean {
    return this.postesAffiches.some((p) => p.est_sensible);
  }

  get optionsNiveaux(): OptionMultiple[] {
    return (this.options?.niveaux ?? []).map((n) => ({ valeur: n.id, libelle: n.libelle }));
  }

  get optionsMatieres(): OptionMultiple[] {
    return (this.options?.matieres ?? []).map((m) => ({ valeur: m.id, libelle: m.libelle }));
  }

  ngOnChanges(changements: SimpleChanges): void {
    if (changements['options']) {
      this.postesSignal.set(this.options?.postes ?? []);
    }
    // Reinitialise a l'ouverture seulement (pas si la liste des postes
    // arrive pendant la saisie : on perdrait ce qui a ete tape).
    const ouverture = changements['isOpen']?.currentValue === true;
    const autreUtilisateur = this.isOpen && !!changements['utilisateur'];
    if (!ouverture && !autreUtilisateur) {
      return;
    }
    const u = this.utilisateur;
    this.formulaire.reset({
      nom: u?.nom ?? '',
      prenoms: u?.prenoms ?? '',
      email: u?.email ?? '',
      telephone: u?.telephone ?? '',
      sexe: u?.sexe ?? null,
      statut_contrat: u?.personnel?.statut_contrat ?? null,
      diplome: u?.personnel?.diplome ?? '',
      date_embauche: u?.personnel?.date_embauche ?? '',
      nationalite: u?.personnel?.nationalite ?? '',
      quartier: u?.personnel?.quartier ?? '',
    });
    this.postesChoisis.set(new Set(u?.postes.map((p) => p.id) ?? []));
    this.niveauxChoisis.set(u?.niveaux.map((n) => n.id) ?? []);
    this.matieresChoisies.set(u?.matieres.map((m) => m.id) ?? []);
    this.erreursServeur.set({});
    this.erreurGenerale.set(null);
    this.tentative.set(false);
  }

  /** On peut toujours retirer un poste ; on n'ajoute que les postes attribuables. */
  posteDesactive(poste: PosteOption): boolean {
    return this.postesVerrouilles || (!poste.attribuable && !this.postesChoisis().has(poste.id));
  }

  infoPoste(poste: PosteOption): string {
    if (!poste.is_active) {
      return 'Poste désactivé : il ne peut plus être attribué.';
    }
    if (poste.est_sensible && !poste.attribuable) {
      return 'Poste protégé : seul le Super admin peut l\'attribuer.';
    }
    return poste.est_sensible ? 'Poste protégé' : '';
  }

  basculerPoste(poste: PosteOption): void {
    if (this.posteDesactive(poste)) {
      return;
    }
    this.postesChoisis.update((choisis) => {
      const suivant = new Set(choisis);
      if (suivant.has(poste.id)) {
        suivant.delete(poste.id);
      } else {
        suivant.add(poste.id);
      }
      return suivant;
    });
    this.effacerErreur('postes');
  }

  choisirValeur(champ: 'sexe' | 'statut_contrat', valeur: string): void {
    const controle = this.formulaire.controls[champ];
    // Re-cliquer sur la valeur choisie la retire (champs facultatifs).
    controle.setValue(controle.value === valeur ? null : (valeur as never));
  }

  /** Message d'erreur d'un champ : validation locale, sinon celle de l'API. */
  erreur(champ: string): string | null {
    const serveur = this.erreursServeur()[champ];
    if (serveur) {
      return serveur;
    }
    if (champ === 'postes') {
      return this.tentative() && !this.postesVerrouilles && this.postesChoisis().size === 0
        ? 'Choisissez au moins un poste.'
        : null;
    }
    const controle = this.formulaire.get(champ);
    if (!controle || !controle.invalid || !(controle.touched || this.tentative())) {
      return null;
    }
    if (controle.hasError('required')) {
      return champ === 'email' ? "L'adresse e-mail sert d'identifiant de connexion : elle est obligatoire." : 'Champ obligatoire.';
    }
    if (controle.hasError('email')) {
      return "Cette adresse e-mail n'est pas valide.";
    }
    return 'Texte trop long.';
  }

  effacerErreur(champ: string): void {
    if (this.erreursServeur()[champ]) {
      const { [champ]: _, ...reste } = this.erreursServeur();
      this.erreursServeur.set(reste);
    }
  }

  enregistrer(): void {
    this.tentative.set(true);
    if (this.formulaire.invalid || (!this.postesVerrouilles && this.postesChoisis().size === 0)) {
      this.formulaire.markAllAsTouched();
      return;
    }

    const v = this.formulaire.getRawValue();
    const vide = (s: string | null | undefined) => (s?.trim() ? s.trim() : null);
    const saisie: UtilisateurSaisie = {
      nom: v.nom!.trim(),
      prenoms: vide(v.prenoms),
      email: v.email!.trim(),
      telephone: vide(v.telephone),
      sexe: v.sexe ?? null,
      postes: [...this.postesChoisis()],
      niveaux: this.afficherNiveaux() ? this.niveauxChoisis() : [],
      matieres: this.afficherMatieres() ? this.matieresChoisies() : [],
      diplome: vide(v.diplome),
      statut_contrat: v.statut_contrat ?? null,
      date_embauche: vide(v.date_embauche),
      nationalite: vide(v.nationalite),
      quartier: vide(v.quartier),
    };

    this.enregistrement.set(true);
    this.erreurGenerale.set(null);

    const fin = () => this.enregistrement.set(false);
    const echec = (erreur: HttpErrorResponse) => {
      fin();
      if (erreur.status === 422 && erreur.error?.errors) {
        const parChamp: Record<string, string> = {};
        for (const [champ, messages] of Object.entries(erreur.error.errors as Record<string, string[]>)) {
          parChamp[champ.split('.')[0]] = messages[0];
        }
        this.erreursServeur.set(parChamp);
      } else if (erreur.status === 403) {
        this.erreurGenerale.set(erreur.error?.message || "Vous n'avez pas le droit de modifier ce compte.");
      } else {
        this.erreurGenerale.set('Enregistrement impossible. Vérifiez votre connexion puis réessayez.');
      }
    };

    if (this.utilisateur) {
      this.service.modifier(this.utilisateur.id, saisie).subscribe({
        next: (u) => {
          fin();
          this.modifie.emit(u);
        },
        error: echec,
      });
    } else {
      this.service.creer(saisie).subscribe({
        next: (reponse) => {
          fin();
          this.cree.emit(reponse);
        },
        error: echec,
      });
    }
  }

  fermer(): void {
    if (!this.enregistrement()) {
      this.ferme.emit();
    }
  }
}

import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, ToastController, IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  addOutline,
  duplicateOutline,
  documentsOutline,
  searchOutline,
  printOutline,
  schoolOutline,
  peopleOutline,
  maleOutline,
  femaleOutline,
  alertCircleOutline,
  layersOutline,
  listOutline,
  createOutline,
  trashOutline,
  closeOutline,
  cloudOfflineOutline,
  personOutline,
  languageOutline,
  businessOutline,
  informationCircleOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { Classe, ClasseService, Cycle, LIBELLES_CYCLE, ListeClasses, SaisieClasse } from '../../core/services/classe.service';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE, paginer } from '../../shared/pagination.component';

/**
 * Classes de l'annee (V1 liste_classes / new_classe) : effectifs par classe,
 * creation, modification, suppression d'une classe vide, impression ; acces
 * a la liste de chaque classe.
 */
@Component({
  selector: 'app-classes',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent, SelecteurComponent, PaginationComponent],
  templateUrl: './classes.page.html',
  styleUrl: './classes.page.scss',
})
export class ClassesPage {
  private readonly service = inject(ClasseService);
  private readonly auth = inject(AuthService);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly libellesCycle = LIBELLES_CYCLE;
  readonly peutGerer = computed(() => this.auth.aPermission('classes.gerer'));

  readonly donnees = signal<ListeClasses | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal(false);
  /** Impression en cours : liste des classes, listes de toutes les classes ou id d'une classe. */
  readonly impression = signal<'classes' | 'listes' | number | null>(null);

  readonly recherche = signal('');
  readonly niveau = signal(0);
  /** Filtres : cycle, LV2 et redoublants (rangs dans les listes, 0 = tous). */
  readonly cycle = signal(0);
  readonly langue = signal(0);
  /** 0 = toutes, 1 = avec redoublants, 2 = sans redoublant. */
  readonly redoublants = signal(0);
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);

  /** Formulaire (modale) : null = creation. */
  readonly modale = signal(false);
  readonly edition = signal<Classe | null>(null);
  readonly saisie = signal<SaisieClasse>(this.vide());
  readonly erreurs = signal<Record<string, string>>({});
  readonly tentative = signal(false);
  readonly enregistrement = signal(false);

  /** Niveaux proposes dans le filtre : ceux qui ont des classes. */
  readonly optionsFiltreNiveau = computed<OptionSelecteur[]>(() => {
    const ids = new Set((this.donnees()?.data ?? []).map((c) => c.niveau?.id));
    const cycle = this.cycle() ? this.cyclesPresents()[this.cycle() - 1] : null;
    return [
      { valeur: 0, libelle: 'Tous les niveaux' },
      ...(this.donnees()?.niveaux ?? []).filter((n) => ids.has(n.id) && (!cycle || n.cycle === cycle)).map((n) => ({ valeur: n.id, libelle: n.libelle })),
    ];
  });

  /** Cycles des classes (ordre maternelle -> lycee). */
  readonly cyclesPresents = computed<Cycle[]>(() => {
    const presents = new Set((this.donnees()?.data ?? []).map((c) => c.niveau?.cycle));
    return (Object.keys(LIBELLES_CYCLE) as Cycle[]).filter((c) => presents.has(c));
  });
  readonly optionsFiltreCycle = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Tous les cycles' },
    ...this.cyclesPresents().map((c, i) => ({ valeur: i + 1, libelle: LIBELLES_CYCLE[c] })),
  ]);
  /** LV2 proposees (Allemand, Espagnol...) puis « Aucune » (null). */
  readonly languesPresentes = computed<(string | null)[]>(() => [...(this.donnees()?.langues ?? []), null]);
  readonly optionsFiltreLangue = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Tous' },
    ...this.languesPresentes().map((l, i) => ({ valeur: i + 1, libelle: l ?? 'Aucune' })),
  ]);
  readonly optionsFiltreRedoublants = [
    { valeur: 0, libelle: 'Avec ou sans redoublants' },
    { valeur: 1, libelle: 'Classes avec redoublants' },
    { valeur: 2, libelle: 'Classes sans redoublant' },
  ];

  readonly classes = computed(() => {
    const texte = this.normaliser(this.recherche());
    const cycle = this.cycle() ? this.cyclesPresents()[this.cycle() - 1] : null;
    const langue = this.langue() ? this.languesPresentes()[this.langue() - 1] : undefined;
    return (this.donnees()?.data ?? []).filter(
      (c) =>
        (!this.niveau() || c.niveau?.id === this.niveau()) &&
        (!cycle || c.niveau?.cycle === cycle) &&
        (langue === undefined || (c.langue_vivante_2 ?? null) === langue) &&
        (!this.redoublants() || (this.redoublants() === 1 ? c.redoublants > 0 : !c.redoublants)) &&
        (!texte || this.normaliser(`${c.libelle} ${c.salle ?? ''} ${c.professeur_principal?.nom ?? ''} ${c.educateur?.nom ?? ''}`).includes(texte)),
    );
  });
  readonly classesPage = computed(() => paginer(this.classes(), this.page(), this.taille()));

  readonly titre = computed(() => {
    const n = this.classes().length;
    const eleves = this.classes().reduce((s, c) => s + c.effectif, 0);
    return `${n} classe${n > 1 ? 's' : ''} · ${eleves} élève${eleves > 1 ? 's' : ''}`;
  });

  // ------------------------------------------------ Formulaire

  readonly optionsNiveau = computed<OptionSelecteur[]>(() =>
    (this.donnees()?.niveaux ?? []).map((n) => ({ valeur: n.id, libelle: `${n.libelle} · ${LIBELLES_CYCLE[n.cycle]}` })),
  );
  readonly optionsLangue = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Aucune' },
    ...(this.donnees()?.langues ?? []).map((l, i) => ({ valeur: i + 1, libelle: l })),
  ]);
  readonly rangLangue = computed(() => (this.donnees()?.langues ?? []).indexOf(this.saisie().langue_vivante_2 ?? '') + 1);
  readonly optionsPersonnel = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Non désigné' },
    ...(this.donnees()?.personnels ?? []).map((p) => ({ valeur: p.id, libelle: p.nom })),
  ]);

  /** Libelle final de la classe (niveau + nom). */
  readonly apercu = computed(() => {
    const n = this.donnees()?.niveaux.find((x) => x.id === this.saisie().niveau_id);
    const nom = this.saisie().nom.trim().toUpperCase();
    return n && nom ? `${n.libelle} ${nom}`.toUpperCase() : '';
  });

  /** Controles du formulaire (affiches apres une tentative d'enregistrement). */
  readonly controles = computed(() => {
    const s = this.saisie();
    const e: Record<string, string> = {};
    if (!s.niveau_id) e['niveau_id'] = 'Choisissez le niveau.';
    const nom = s.nom.trim().toUpperCase();
    const chiffres = this.donnees()?.numerotation !== 'lettres';
    // Une classe existante garde son nom meme si la numerotation a change depuis.
    const inchange = !!this.edition() && nom === this.edition()!.nom.toUpperCase();
    if (!nom) e['nom'] = chiffres ? 'Indiquez le numéro de la classe (ex : 1, 2, 3).' : 'Indiquez la lettre de la classe (ex : A, B, C).';
    else if (!inchange && chiffres && !/^[1-9]\d{0,2}$/.test(nom)) e['nom'] = 'Numérotation en chiffres : un numéro de 1 à 999.';
    else if (!inchange && !chiffres && !/^[A-Z]$/.test(nom)) e['nom'] = 'Numérotation en lettres : une lettre de A à Z.';
    if (s.capacite !== null && (!Number.isInteger(s.capacite) || s.capacite < 1 || s.capacite > 500)) e['capacite'] = 'Nombre entier entre 1 et 500.';
    return e;
  });

  /** Creation de plusieurs classes (modale). */
  readonly modaleLot = signal(false);
  readonly lotNiveau = signal(0);
  readonly lotNombre = signal<number | null>(1);
  readonly lotTentative = signal(false);
  readonly lotErreurs = signal<Record<string, string>>({});
  readonly lotControles = computed(() => {
    const e: Record<string, string> = {};
    const n = this.lotNombre();
    if (!this.lotNiveau()) e['niveau_id'] = 'Choisissez le niveau.';
    if (n === null || !Number.isInteger(n) || n < 1 || n > 20) e['nombre'] = 'Nombre entier entre 1 et 20.';
    return e;
  });
  /** Classes qui seront creees : a la suite de la plus grande du niveau. */
  readonly lotApercu = computed(() => {
    const d = this.donnees();
    const niveau = d?.niveaux.find((x) => x.id === this.lotNiveau());
    const n = this.lotNombre();
    const premier = niveau ? d?.prochains[niveau.id] : null;
    if (!niveau || !premier || !n || n < 1 || n > 20) return [];
    const noms: string[] = [];
    for (let i = 0; i < n; i++) {
      if (/^\d+$/.test(premier)) noms.push(String(Number(premier) + i));
      else if (premier.charCodeAt(0) + i <= 90) noms.push(String.fromCharCode(premier.charCodeAt(0) + i));
    }
    return noms.map((nom) => `${niveau.libelle} ${nom}`.toUpperCase());
  });

  /** Le niveau ne change plus quand la classe a des eleves. */
  readonly niveauVerrouille = computed(() => (this.edition()?.effectif ?? 0) > 0);

  constructor() {
    addIcons({
      addOutline,
      duplicateOutline,
      documentsOutline,
      searchOutline,
      printOutline,
      schoolOutline,
      peopleOutline,
      maleOutline,
      femaleOutline,
      alertCircleOutline,
      layersOutline,
      listOutline,
      createOutline,
      trashOutline,
      closeOutline,
      cloudOfflineOutline,
      personOutline,
      languageOutline,
      businessOutline,
      informationCircleOutline,
    });
  }

  /** Retour sur la page (apres une inscription...) : effectifs a jour. */
  ionViewWillEnter(): void {
    this.charger();
  }

  charger(evenement?: CustomEvent): void {
    if (!this.donnees()) {
      this.chargement.set(true);
    }
    this.erreur.set(false);
    this.service.lister().subscribe({
      next: (d) => {
        this.donnees.set(d);
        this.chargement.set(false);
        if (this.niveau() && !d.data.some((c) => c.niveau?.id === this.niveau())) {
          this.niveau.set(0);
        }
        if (this.cycle() > this.cyclesPresents().length) this.cycle.set(0);
        if (this.langue() > this.languesPresentes().length) this.langue.set(0);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
    });
  }

  rechercher(texte: string): void {
    this.recherche.set(texte);
    this.page.set(1);
  }

  choisirNiveau(id: number): void {
    this.niveau.set(id);
    this.page.set(1);
  }

  /** Filtre cycle : le niveau choisi doit rester dans le cycle. */
  choisirCycle(rang: number): void {
    this.cycle.set(rang);
    const cycle = rang ? this.cyclesPresents()[rang - 1] : null;
    if (cycle && this.niveau() && this.donnees()?.niveaux.find((n) => n.id === this.niveau())?.cycle !== cycle) {
      this.niveau.set(0);
    }
    this.page.set(1);
  }

  choisirFiltre(filtre: 'langue' | 'redoublants', valeur: number): void {
    this[filtre].set(valeur);
    this.page.set(1);
  }

  changerTaille(taille: number): void {
    this.taille.set(taille);
    this.page.set(1);
  }

  ouvrirListe(c: Classe): void {
    this.router.navigate(['/tabs/classes', c.id]);
  }

  async imprimer(): Promise<void> {
    const d = this.donnees();
    if (!d) return;
    this.impression.set('classes');
    // Les classes affichees (tous filtres compris).
    await this.service.imprimerClasses({ ids: this.classes().map((c) => c.id) }, d.annee);
    this.impression.set(null);
  }

  /** Listes des eleves des classes affichees dans le tableau (filtres compris). */
  async imprimerListes(): Promise<void> {
    const d = this.donnees();
    if (!d || !this.classes().length) return;
    this.impression.set('listes');
    await this.service.imprimerListes(this.classes(), d.annee);
    this.impression.set(null);
  }

  async imprimerClasse(c: Classe, evenement: Event): Promise<void> {
    evenement.stopPropagation();
    this.impression.set(c.id);
    await this.service.imprimerListes([c], this.donnees()?.annee ?? '');
    this.impression.set(null);
  }

  // ------------------------------------------------ Creation, modification, suppression

  nouvelle(): void {
    const saisie = this.vide();
    // Reprend le niveau filtre et propose le nom suivant.
    saisie.niveau_id = this.niveau() || 0;
    saisie.nom = saisie.niveau_id ? this.nomSuivant(saisie.niveau_id) : '';
    this.ouvrirFormulaire(null, saisie);
  }

  modifier(c: Classe, evenement?: Event): void {
    evenement?.stopPropagation();
    this.ouvrirFormulaire(c, {
      niveau_id: c.niveau?.id ?? 0,
      nom: c.nom,
      salle: c.salle,
      capacite: c.capacite,
      langue_vivante_2: c.langue_vivante_2,
      professeur_principal_id: c.professeur_principal?.id ?? null,
      educateur_id: c.educateur?.id ?? null,
    });
  }

  async supprimer(c: Classe, evenement?: Event): Promise<void> {
    evenement?.stopPropagation();
    if (c.effectif > 0) {
      this.notifier(`${c.libelle} compte ${c.effectif} élève${c.effectif > 1 ? 's' : ''} : elle ne peut pas être supprimée.`, true);
      return;
    }
    const alerte = await this.alertes.create({
      header: 'Supprimer la classe',
      cssClass: 'alerte-me',
      message: `La classe ${c.libelle} sera supprimée. Confirmez-vous ?`,
      buttons: [
        { text: 'Non', role: 'cancel' },
        { text: 'Oui, supprimer', role: 'destructive' },
      ],
    });
    await alerte.present();
    const { role } = await alerte.onDidDismiss();
    if (role !== 'destructive') return;
    this.service.supprimer(c.id).subscribe({
      next: (r) => {
        this.notifier(r.message);
        this.charger();
      },
      error: (e: HttpErrorResponse) => this.notifier(e.error?.message || 'Suppression impossible.', true),
    });
  }

  // ------------------------------------------------ Plusieurs classes

  nouvellesClasses(): void {
    this.lotNiveau.set(this.niveau() || 0);
    this.lotNombre.set(1);
    this.lotTentative.set(false);
    this.lotErreurs.set({});
    this.modaleLot.set(true);
  }

  choisirLotNiveau(id: number): void {
    this.lotNiveau.set(id);
    this.lotErreurs.set({});
  }

  saisirLotNombre(texte: string): void {
    this.lotNombre.set(texte.trim() === '' ? null : Number(texte));
    this.lotErreurs.set({});
  }

  erreurLot(champ: string): string | null {
    return this.lotErreurs()[champ] ?? (this.lotTentative() ? this.lotControles()[champ] ?? null : null);
  }

  creerLot(): void {
    this.lotTentative.set(true);
    if (Object.keys(this.lotControles()).length) {
      return;
    }
    this.enregistrement.set(true);
    this.service.creerLot(this.lotNiveau(), this.lotNombre()!).subscribe({
      next: (classes) => {
        this.enregistrement.set(false);
        this.modaleLot.set(false);
        this.notifier(classes.length > 1 ? `${classes.length} classes créées : ${classes.map((c) => c.libelle).join(', ')}.` : `Classe ${classes[0]?.libelle} créée.`);
        this.charger();
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrement.set(false);
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        if (erreurs) {
          this.lotErreurs.set(Object.fromEntries(Object.entries(erreurs).map(([k, v]) => [k, v[0]])));
        } else {
          this.notifier(e.error?.message || 'Création impossible. Vérifiez votre connexion puis réessayez.', true);
        }
      },
    });
  }

  majSaisie<K extends keyof SaisieClasse>(champ: K, valeur: SaisieClasse[K]): void {
    this.saisie.update((s) => ({ ...s, [champ]: valeur }));
    this.erreurs.update((e) => {
      const { [champ]: _retire, ...reste } = e;
      return reste;
    });
  }

  choisirNiveauSaisie(id: number): void {
    const avant = this.saisie();
    this.majSaisie('niveau_id', id);
    // En creation, propose le nom suivant tant que l'utilisateur n'a rien saisi de different.
    if (!this.edition() && (!avant.nom || avant.nom === this.nomSuivant(avant.niveau_id))) {
      this.majSaisie('nom', this.nomSuivant(id));
    }
  }

  saisirCapacite(texte: string): void {
    this.majSaisie('capacite', texte.trim() === '' ? null : Number(texte));
  }

  choisirLangue(rang: number): void {
    this.majSaisie('langue_vivante_2', this.donnees()?.langues[rang - 1] ?? null);
  }

  erreurChamp(champ: string): string | null {
    return this.erreurs()[champ] ?? (this.tentative() ? this.controles()[champ] ?? null : null);
  }

  enregistrer(): void {
    this.tentative.set(true);
    if (Object.keys(this.controles()).length) {
      return;
    }
    const s = this.saisie();
    const saisie: SaisieClasse = { ...s, nom: s.nom.trim().toUpperCase(), salle: s.salle?.trim() || null };
    const edition = this.edition();
    this.enregistrement.set(true);
    (edition ? this.service.modifier(edition.id, saisie) : this.service.creer(saisie)).subscribe({
      next: (c) => {
        this.enregistrement.set(false);
        this.modale.set(false);
        this.notifier(edition ? `Classe ${c.libelle} modifiée.` : `Classe ${c.libelle} créée.`);
        this.charger();
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrement.set(false);
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        if (erreurs) {
          this.erreurs.set(Object.fromEntries(Object.entries(erreurs).map(([k, v]) => [k, v[0]])));
        } else {
          this.notifier(e.error?.message || 'Enregistrement impossible. Vérifiez votre connexion puis réessayez.', true);
        }
      },
    });
  }

  // ------------------------------------------------ Affichage

  /** Remplissage de la classe (0-100) par rapport a sa limite. */
  remplissage(c: Classe): number {
    return c.limite ? Math.min(100, Math.round((c.effectif / c.limite) * 100)) : 0;
  }

  libelleLimite(c: Classe): string {
    if (!c.limite) return 'Pas de limite';
    const source = c.source_limite === 'classe' ? 'classe' : c.source_limite === 'niveau' ? 'niveau' : 'générale';
    return `Limite ${c.limite} (${source})`;
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private ouvrirFormulaire(classe: Classe | null, saisie: SaisieClasse): void {
    this.edition.set(classe);
    this.saisie.set(saisie);
    this.erreurs.set({});
    this.tentative.set(false);
    this.modale.set(true);
  }

  /** Nom propose pour la prochaine classe du niveau (numerotation de l'etablissement). */
  private nomSuivant(niveauId: number): string {
    return this.donnees()?.prochains[niveauId] ?? '';
  }

  private vide(): SaisieClasse {
    return { niveau_id: 0, nom: '', salle: null, capacite: null, langue_vivante_2: null, professeur_principal_id: null, educateur_id: null };
  }

  private normaliser(texte: string): string {
    return texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}

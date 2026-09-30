import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonSpinner, IonSkeletonText } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  chevronForwardOutline,
  searchOutline,
  personAddOutline,
  refreshOutline,
  alertCircleOutline,
  checkmarkCircle,
  checkmarkCircleOutline,
  checkmarkOutline,
  ellipseOutline,
  globeOutline,
  informationCircleOutline,
  cameraOutline,
  trashOutline,
  schoolOutline,
  timeOutline,
  swapHorizontalOutline,
  cloudOfflineOutline,
  eyeOutline,
  lockClosedOutline,
  warningOutline,
  cubeOutline,
} from 'ionicons/icons';
import { forkJoin, of, catchError } from 'rxjs';
import { InscriptionService } from '../../core/services/inscription.service';
import {
  ApercuFrais,
  ClasseInscription,
  DetailInscription,
  DetteEleve,
  FicheEleve,
  InscriptionPrecedente,
  LIBELLES_DECISION,
  LienParente,
  OptionsInscription,
  ParentEleve,
  ResultatEnLigne,
  ResultatMatricule,
  SaisieInscription,
} from '../../core/models/inscription.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { RecuEnLigneComponent } from './recu-en-ligne.component';
import { Ecart, calculerEcarts } from './en-ligne';

type Etape = 1 | 2 | 3 | 4;
type ChampsEleve = Omit<FicheEleve, 'parents' | 'photo_url' | 'id'>;
type ChampsInscription = SaisieInscription['inscription'];
interface DecisionPrecedente {
  decision_finale: string | null;
  niveau_a_suivre_id: number | null;
  moyenne_annuelle: number | null;
}

const LIENS: { lien: LienParente; libelle: string }[] = [
  { lien: 'pere', libelle: 'Père' },
  { lien: 'mere', libelle: 'Mère' },
  { lien: 'tuteur_legal', libelle: 'Tuteur' },
];

/**
 * Assistant d'inscription (V1 EleveFacade::finaliserInscr) :
 * 1. matricule (+ inscription en ligne sur le site de l'Etat) ;
 * 2. fiche de l'eleve et parents ;
 * 3. statut, resultat de l'annee precedente, niveau et classe ;
 * 4. recapitulatif et frais qui seront copies dans l'inscription.
 * En modification, on arrive directement a l'etape 2.
 */
@Component({
  selector: 'app-inscription-formulaire',
  standalone: true,
  imports: [IonContent, IonIcon, IonSpinner, IonSkeletonText, SelecteurComponent, RecuEnLigneComponent],
  templateUrl: './inscription-formulaire.page.html',
  styleUrl: './inscription-formulaire.page.scss',
})
export class InscriptionFormulairePage implements OnInit, OnDestroy {
  private readonly service = inject(InscriptionService);
  private readonly route = inject(ActivatedRoute);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly liens = LIENS;
  readonly libellesDecision = LIBELLES_DECISION;
  readonly etapes: { numero: Etape; libelle: string }[] = [
    { numero: 1, libelle: 'Matricule' },
    { numero: 2, libelle: 'Fiche élève' },
    { numero: 3, libelle: 'Scolarité' },
    { numero: 4, libelle: 'Récapitulatif' },
  ];

  readonly inscriptionId = signal<number | null>(null);
  readonly modification = computed(() => this.inscriptionId() !== null);
  readonly etape = signal<Etape>(1);
  readonly options = signal<OptionsInscription | null>(null);
  readonly chargement = signal(true);
  readonly erreurChargement = signal(false);

  // ------------------------------------------------ Etape 1
  readonly matricule = signal('');
  readonly recherche = signal(false);
  readonly erreurMatricule = signal<string | null>(null);
  readonly resultat = signal<ResultatMatricule | null>(null);
  readonly enLigne = signal<ResultatEnLigne | null>(null);
  readonly rechercheEnLigne = signal(false);
  readonly ecartsChoisis = signal<Set<string>>(new Set());

  // ------------------------------------------------ Saisie
  readonly eleve = signal<ChampsEleve>(this.eleveVide(''));
  readonly parents = signal<ParentEleve[]>(LIENS.map((l) => this.parentVide(l.lien)));
  readonly inscription = signal<ChampsInscription>(this.inscriptionVide());
  readonly precedente = signal<InscriptionPrecedente | null>(null);
  readonly decision = signal<DecisionPrecedente>({ decision_finale: null, niveau_a_suivre_id: null, moyenne_annuelle: null });
  readonly dettes = signal<DetteEleve[]>([]);
  readonly photo = signal<File | null>(null);
  readonly apercuPhoto = signal<string | null>(null);
  readonly photoActuelle = signal<string | null>(null);
  /** La photo du recu en ligne n'a pas pu s'afficher (l'eleve n'en a pas). */
  readonly photoEnLigneIndisponible = signal(false);
  /** Photo du recu de l'inscription en ligne, enregistree a la validation. */
  readonly photoEnLigne = computed(() => (this.photoEnLigneIndisponible() ? null : this.enLigne()?.donnees?.photo_url ?? null));
  /** Photo affichee : choisie a la main > inscription en ligne > photo actuelle. */
  readonly photoAffichee = computed(() => this.apercuPhoto() || this.photoEnLigne() || this.photoActuelle());
  readonly sourcePhoto = computed(() =>
    this.apercuPhoto() ? 'Photo choisie' : this.photoEnLigne() ? 'Photo de l\'inscription en ligne' : this.photoActuelle() ? 'Photo actuelle' : null,
  );
  /** Classe actuelle (modification) : sa place est deja comptee dans l'effectif. */
  readonly classeInitiale = signal<number | null>(null);
  readonly modifiableTarif = signal(true);
  /** Modification ouverte depuis "Mise à jour inscription en ligne" (fiche). */
  readonly depuisEnLigne = signal(false);

  readonly erreurs = signal<Record<string, string>>({});
  readonly apercu = signal<ApercuFrais | null>(null);
  readonly chargementApercu = signal(false);
  readonly enregistrement = signal(false);

  // ------------------------------------------------ Derives
  readonly niveauxAccessibles = computed(() => this.options()?.niveaux ?? []);

  /** Niveaux : ceux dont les montants d'inscription manquent pour ce statut sont signales. */
  readonly optionsNiveaux = computed<OptionSelecteur[]>(() => {
    const affecte = this.inscription().affecte;
    return this.niveauxAccessibles().map((n) => {
      const tarif = affecte ? n.tarifs.affecte : n.tarifs.non_affecte;
      return {
        valeur: n.id,
        libelle: n.libelle,
        detail: tarif ? `${n.classes.length} classe${n.classes.length > 1 ? 's' : ''}` : `Montants ${affecte ? 'affecté' : 'non affecté'} non renseignés`,
        badge: tarif ? null : { texte: 'Tarifs manquants', ton: 'attention' as const },
      };
    });
  });

  /** Niveau et statut affecte d'origine (modification) : le tarif n'est controle que s'ils changent, comme l'API. */
  private readonly tarifInitial = signal<{ niveau: number | null; affecte: boolean } | null>(null);

  tarifAVerifier(): boolean {
    const t = this.tarifInitial();
    return !t || t.niveau !== this.inscription().niveau_id || t.affecte !== this.inscription().affecte;
  }

  messageTarif(): string {
    return `Les montants d'inscription de ${this.niveauChoisi()?.libelle} (élève ${this.inscription().affecte ? 'affecté' : 'non affecté'}) ne sont pas renseignés : complétez Paramètres › Paiements avant d'inscrire.`;
  }

  /** Montants d'inscription du niveau choisi manquants (inscription impossible). */
  readonly tarifManquant = computed(() => {
    const n = this.niveauChoisi();
    return !!n && !(this.inscription().affecte ? n.tarifs.affecte : n.tarifs.non_affecte);
  });

  readonly optionsTousNiveaux = computed<OptionSelecteur[]>(() =>
    (this.options()?.tous_niveaux ?? []).map((n) => ({ valeur: n.id, libelle: n.libelle })),
  );

  readonly classesDuNiveau = computed<ClasseInscription[]>(
    () => this.niveauxAccessibles().find((n) => n.id === this.inscription().niveau_id)?.classes ?? [],
  );

  readonly classeChoisie = computed(() => this.classesDuNiveau().find((c) => c.id === this.inscription().classe_id) ?? null);

  readonly niveauChoisi = computed(() => this.niveauxAccessibles().find((n) => n.id === this.inscription().niveau_id) ?? null);

  /** Liste deroulante des classes du niveau (0 = choisir plus tard). */
  readonly optionsClasses = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Choisir plus tard', detail: 'Les frais seront fixés au choix de la classe' },
    ...this.classesDuNiveau().map((c) => ({
      valeur: c.id,
      libelle: c.libelle,
      detail: `${c.effectif}${c.limite ? ' / ' + c.limite : ''} élève${c.effectif > 1 ? 's' : ''} · ${c.garcons} G · ${c.filles} F${c.langue_vivante_2 ? ' · ' + c.langue_vivante_2 : ''}`,
      badge: this.estComplete(c) ? { texte: 'Complète', ton: 'attention' as const } : null,
      desactivee: this.estComplete(c),
    })),
  ]);

  /** "8 chiffres + 1 lettre, ex : 18583394P" pour chaque format accepte. */
  readonly exemplesFormats = computed(() =>
    (this.options()?.formats_matricule ?? [])
      .map((f) => {
        const groupes = (f.match(/9+|A+|[^9A]+/g) ?? []).map((g) => (g[0] === '9' ? `${g.length} chiffre${g.length > 1 ? 's' : ''}` : g[0] === 'A' ? `${g.length} lettre${g.length > 1 ? 's' : ''}` : `« ${g} »`));
        let c = 0;
        let l = 0;
        const exemple = [...f].map((x) => (x === '9' ? '18583394123'[c++ % 11] : x === 'A' ? 'PKABCD'[l++ % 6] : x)).join('');
        return `${groupes.join(' + ')}, ex : ${exemple}`;
      })
      .join(' ou '),
  );

  readonly ecarts = computed<Ecart[]>(() => {
    const recu = this.enLigne()?.donnees;
    const r = this.resultat();
    if (!recu || !r || r.cas !== 'reinscription') {
      return [];
    }
    return calculerEcarts(recu, r.eleve, r.precedente?.affecte ?? null);
  });

  readonly contactPrincipal = computed(() => this.parents().find((p) => p.is_contact_principal)?.lien_parente ?? null);
  readonly payeur = computed(() => this.parents().find((p) => p.is_payeur)?.lien_parente ?? null);
  readonly parentsRenseignes = computed(() => this.parents().filter((p) => p.nom_complet.trim() || p.telephone?.trim()));
  readonly totalDettes = computed(() => this.dettes().reduce((s, d) => s + d.reste, 0));

  readonly etapeAccessible = computed(() => {
    const r = this.resultat();
    return this.modification() || (!!r && r.cas !== 'deja_inscrit');
  });

  constructor() {
    addIcons({
      chevronBackOutline,
      chevronForwardOutline,
      searchOutline,
      personAddOutline,
      refreshOutline,
      alertCircleOutline,
      checkmarkCircle,
      checkmarkCircleOutline,
      checkmarkOutline,
      ellipseOutline,
      globeOutline,
      informationCircleOutline,
      cameraOutline,
      trashOutline,
      schoolOutline,
      timeOutline,
      swapHorizontalOutline,
      cloudOfflineOutline,
      eyeOutline,
      lockClosedOutline,
      warningOutline,
      cubeOutline,
    });
  }

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id')) || null;
    this.inscriptionId.set(id);
    this.charger();
  }

  ngOnDestroy(): void {
    this.libererApercuPhoto();
  }

  charger(): void {
    this.chargement.set(true);
    this.erreurChargement.set(false);
    const id = this.inscriptionId();
    forkJoin({ options: this.service.options(), detail: id ? this.service.detail(id) : of(null) }).subscribe({
      next: ({ options, detail }) => {
        this.options.set(options);
        if (detail) {
          this.remplirDepuisDetail(detail);
          this.etape.set(2);
          this.reprendreEnLigne();
        }
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreurChargement.set(true);
      },
    });
  }

  // ================================================================ Etape 1

  rechercherMatricule(): void {
    const matricule = this.matricule().trim().toUpperCase();
    this.matricule.set(matricule);
    this.erreurMatricule.set(null);
    if (!matricule) {
      this.erreurMatricule.set('Saisissez le matricule de l\'élève.');
      return;
    }
    if (!this.formatValide(matricule)) {
      this.erreurMatricule.set(`Matricule invalide. Format attendu : ${this.exemplesFormats()}.`);
      return;
    }

    this.recherche.set(true);
    this.resultat.set(null);
    this.enLigne.set(null);
    this.photoEnLigneIndisponible.set(false);
    this.service.rechercherMatricule(matricule).subscribe({
      next: (r) => {
        this.recherche.set(false);
        this.resultat.set(r);
        this.preparerSaisie(r);
        if (r.cas !== 'deja_inscrit' && this.options()?.verification_en_ligne) {
          this.verifierEnLigne(matricule);
        }
      },
      error: (e: HttpErrorResponse) => {
        this.recherche.set(false);
        this.erreurMatricule.set(this.premierMessage(e) ?? 'Recherche impossible. Vérifiez votre connexion puis réessayez.');
      },
    });
  }

  verifierEnLigne(matricule = this.matricule()): void {
    this.rechercheEnLigne.set(true);
    this.service
      .enLigne(matricule)
      .pipe(catchError(() => of<ResultatEnLigne>({ trouve: false, erreur: 'Vérification impossible pour le moment.' })))
      .subscribe((r) => {
        this.rechercheEnLigne.set(false);
        this.enLigne.set(r);
        if (!r.trouve) {
          return;
        }
        if (this.resultat()?.cas === 'nouveau') {
          // Nouvel eleve : la fiche est pre-remplie avec le recu.
          this.appliquerEnLigne(null);
        } else {
          // Reinscription : les ecarts sont proposes, tous coches par defaut.
          this.ecartsChoisis.set(new Set(this.ecarts().map((e) => e.cle)));
          this.appliquerEnLigne(new Set());
        }
      });
  }

  /**
   * Arrivee depuis la fiche ("Mise à jour inscription en ligne") : le recu et
   * les differences cochees sont reportes dans la saisie, a verifier puis
   * enregistrer.
   */
  private reprendreEnLigne(): void {
    const etat = history.state as { enLigne?: ResultatEnLigne; champs?: string[] } | null;
    if (!etat?.enLigne?.trouve) {
      return;
    }
    this.enLigne.set(etat.enLigne);
    this.appliquerEnLigne(new Set(etat.champs ?? []));
    this.depuisEnLigne.set(true);
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

  /**
   * Reporte les informations du recu en ligne dans la saisie.
   * champs = null : tout (nouvel eleve) ; sinon seulement les ecarts choisis
   * (+ les informations d'inscription, toujours reprises).
   */
  appliquerEnLigne(champs: Set<string> | null): void {
    const r = this.enLigne();
    const recu = r?.donnees;
    if (!recu) {
      return;
    }
    const prendre = (cle: string) => champs === null || champs.has(cle);
    this.eleve.update((e) => ({
      ...e,
      nom: prendre('nom') && recu.nom ? recu.nom : e.nom,
      prenoms: prendre('prenoms') && recu.prenoms ? recu.prenoms : e.prenoms,
      date_naissance: prendre('date_naissance') && recu.date_naissance ? recu.date_naissance : e.date_naissance,
      lieu_naissance: prendre('lieu_naissance') && recu.lieu_naissance ? recu.lieu_naissance : e.lieu_naissance,
      sexe: prendre('sexe') && recu.sexe ? recu.sexe : e.sexe,
    }));

    // Contact du parent donne en ligne : sur le contact principal s'il n'a pas de numero.
    if (recu.contact_parent && !this.parents().some((p) => p.telephone === recu.contact_parent)) {
      const cible = this.parents().find((p) => p.is_contact_principal && !p.telephone) ?? (this.parentsRenseignes().length === 0 ? this.parents()[2] : null);
      if (cible) {
        this.majParent(cible.lien_parente, 'telephone', recu.contact_parent);
        this.choisirContact(cible.lien_parente);
      }
    }

    this.inscription.update((i) => ({
      ...i,
      affecte: prendre('affecte') && recu.affecte !== undefined ? recu.affecte : i.affecte,
      inscrit_en_ligne: true,
      inscription_en_ligne: recu,
      decision_origine: this.precedente() ? i.decision_origine : (recu.decision ?? i.decision_origine),
      moyenne_origine: this.precedente() ? i.moyenne_origine : (recu.moyenne ?? i.moyenne_origine),
      classe_origine: this.precedente() ? i.classe_origine : (i.classe_origine || recu.niveau_precedent || null),
    }));

    // Resultat de l'annee precedente s'il manque encore chez nous.
    if (this.precedente() && !this.precedente()!.decision_finale && recu.decision) {
      this.decision.set({
        decision_finale: this.options()?.decisions.includes(recu.decision) ? recu.decision : null,
        niveau_a_suivre_id: r?.niveau_suivant_id ?? null,
        moyenne_annuelle: recu.moyenne ?? null,
      });
    }
    this.proposerNiveau();
  }

  appliquerEcarts(): void {
    this.appliquerEnLigne(this.ecartsChoisis());
    this.notifier('Informations du site de l\'État reprises.');
  }

  recommencer(): void {
    this.resultat.set(null);
    this.enLigne.set(null);
    this.photoEnLigneIndisponible.set(false);
    this.matricule.set('');
    this.erreurs.set({});
  }

  // ================================================================ Saisie

  majEleve<K extends keyof ChampsEleve>(champ: K, valeur: ChampsEleve[K]): void {
    this.eleve.update((e) => ({ ...e, [champ]: valeur }));
    this.effacerErreur(`eleve.${champ}`);
  }

  majParent(lien: LienParente, champ: 'nom_complet' | 'telephone' | 'profession', valeur: string): void {
    this.parents.update((liste) => liste.map((p) => (p.lien_parente === lien ? { ...p, [champ]: valeur } : p)));
  }

  choisirContact(lien: LienParente): void {
    this.parents.update((liste) => liste.map((p) => ({ ...p, is_contact_principal: p.lien_parente === lien })));
  }

  choisirPayeur(lien: LienParente): void {
    this.parents.update((liste) => liste.map((p) => ({ ...p, is_payeur: p.lien_parente === lien })));
  }

  parent(lien: LienParente): ParentEleve {
    return this.parents().find((p) => p.lien_parente === lien)!;
  }

  parentIndisponible(lien: LienParente): boolean {
    return (lien === 'pere' && this.eleve().orphelin_pere) || (lien === 'mere' && this.eleve().orphelin_mere);
  }

  majInscription<K extends keyof ChampsInscription>(champ: K, valeur: ChampsInscription[K]): void {
    this.inscription.update((i) => ({ ...i, [champ]: valeur }));
    this.effacerErreur(`inscription.${champ}`);
  }

  choisirNiveau(id: number): void {
    this.inscription.update((i) => ({ ...i, niveau_id: id, classe_id: this.classesDe(id).some((c) => c.id === i.classe_id) ? i.classe_id : null }));
    this.effacerErreur('inscription.niveau_id');
  }

  choisirClasseParId(id: number): void {
    this.choisirClasse(this.classesDuNiveau().find((c) => c.id === id) ?? null);
  }

  choisirClasse(c: ClasseInscription | null): void {
    if (c && this.estComplete(c)) {
      return;
    }
    this.inscription.update((i) => ({
      ...i,
      classe_id: c?.id ?? null,
      langue_vivante_2: i.langue_vivante_2 ?? c?.langue_vivante_2 ?? null,
    }));
    this.effacerErreur('inscription.classe_id');
  }

  /** La classe actuelle de l'eleve (modification) n'est pas "complete" pour lui. */
  estComplete(c: ClasseInscription): boolean {
    return c.complete && c.id !== this.classeInitiale();
  }

  choisirDecision(decision: string | null): void {
    this.decision.update((d) => ({ ...d, decision_finale: decision, niveau_a_suivre_id: null }));
    this.proposerNiveau(true);
  }

  majDecision<K extends keyof DecisionPrecedente>(champ: K, valeur: DecisionPrecedente[K]): void {
    this.decision.update((d) => ({ ...d, [champ]: valeur }));
    if (champ === 'niveau_a_suivre_id' && valeur && this.niveauxAccessibles().some((n) => n.id === valeur)) {
      this.choisirNiveau(valeur as number);
    }
  }

  /**
   * Niveau propose : niveau a suivre saisi, sinon admis = niveau suivant,
   * redouble = meme niveau (et redoublant coche), sinon niveau du recu en ligne.
   */
  proposerNiveau(forcer = false): void {
    if (this.modification() && !forcer) {
      return;
    }
    const prec = this.precedente();
    const d = this.decision();
    let niveau: number | null = d.niveau_a_suivre_id;
    if (!niveau && prec?.niveau_id && d.decision_finale) {
      niveau = d.decision_finale === 'REDOUBLE' ? prec.niveau_id : d.decision_finale === 'ADMIS' ? this.niveauSuivant(prec.niveau_id) : null;
      if (niveau) {
        this.decision.update((x) => ({ ...x, niveau_a_suivre_id: niveau }));
      }
    }
    niveau ??= this.enLigne()?.niveau_suivant_id ?? null;
    if (niveau && this.niveauxAccessibles().some((n) => n.id === niveau)) {
      this.choisirNiveau(niveau);
    }
    if (prec && d.decision_finale) {
      this.majInscription('redoublant', d.decision_finale === 'REDOUBLE');
    } else if (!prec && this.enLigne()?.donnees?.decision) {
      this.majInscription('redoublant', this.enLigne()!.donnees!.decision === 'REDOUBLE');
    }
  }

  choisirPhoto(evenement: Event): void {
    const fichier = (evenement.target as HTMLInputElement).files?.[0] ?? null;
    (evenement.target as HTMLInputElement).value = '';
    if (!fichier) {
      return;
    }
    if (!fichier.type.startsWith('image/')) {
      this.notifier('Choisissez une image (photo).', true);
      return;
    }
    if (fichier.size > 4 * 1024 * 1024) {
      this.notifier('La photo ne doit pas dépasser 4 Mo.', true);
      return;
    }
    this.libererApercuPhoto();
    this.photo.set(fichier);
    this.apercuPhoto.set(URL.createObjectURL(fichier));
  }

  retirerPhoto(): void {
    this.libererApercuPhoto();
    this.photo.set(null);
  }

  // ================================================================ Navigation

  allerA(etape: Etape): void {
    if (etape === 1 && this.modification()) {
      return;
    }
    if (etape > 1 && !this.etapeAccessible()) {
      return;
    }
    // On ne saute pas une etape incomplete.
    for (let e = this.etape(); e < etape; e++) {
      if (!this.etapeValide(e as Etape)) {
        return;
      }
    }
    this.etape.set(etape);
    if (etape === 4) {
      this.chargerApercu();
    }
    (document.querySelector('ion-content.page-inscription') as HTMLIonContentElement | null)?.scrollToTop(200);
  }

  suivant(): void {
    const e = this.etape();
    if (e < 4) {
      this.allerA((e + 1) as Etape);
    }
  }

  precedent(): void {
    const e = this.etape();
    if (e > (this.modification() ? 2 : 1)) {
      this.etape.set((e - 1) as Etape);
    }
  }

  /** Controle leger avant de passer a l'etape suivante (l'API revalide tout). */
  etapeValide(etape: Etape): boolean {
    const erreurs: Record<string, string> = {};
    if (etape === 1 && !this.etapeAccessible()) {
      return false;
    }
    if (etape === 2) {
      const e = this.eleve();
      if (!e.nom.trim()) erreurs['eleve.nom'] = 'Le nom est obligatoire.';
      if (!e.prenoms.trim()) erreurs['eleve.prenoms'] = 'Les prénoms sont obligatoires.';
      if (!e.sexe) erreurs['eleve.sexe'] = 'Précisez le sexe.';
      if (this.modification() && !this.formatValide(e.matricule)) erreurs['eleve.matricule'] = `Format attendu : ${this.exemplesFormats()}.`;
    }
    if (etape === 3 && !this.inscription().niveau_id) {
      erreurs['inscription.niveau_id'] = 'Choisissez le niveau.';
    } else if (etape === 3 && this.tarifManquant() && this.tarifAVerifier()) {
      erreurs['inscription.niveau_id'] = this.messageTarif();
    }
    this.erreurs.set({ ...this.erreurs(), ...erreurs });
    return Object.keys(erreurs).length === 0;
  }

  chargerApercu(): void {
    const i = this.inscription();
    if (!i.niveau_id) {
      return;
    }
    this.chargementApercu.set(true);
    this.service.apercuFrais(i.niveau_id, i.affecte).subscribe({
      next: (a) => {
        this.apercu.set(a);
        this.chargementApercu.set(false);
      },
      error: () => {
        this.apercu.set(null);
        this.chargementApercu.set(false);
      },
    });
  }

  // ================================================================ Enregistrement

  enregistrer(): void {
    if (this.enregistrement()) {
      return;
    }
    const saisie: SaisieInscription = {
      eleve: this.eleve(),
      parents: this.parents().filter((p) => !this.parentIndisponible(p.lien_parente)),
      inscription: this.inscription(),
      precedente: this.precedente() ? this.decision() : null,
      // Pas de photo choisie a la main : l'API enregistre celle du recu en ligne.
      photo_en_ligne: !this.photo() && !!this.photoEnLigne(),
    };
    const id = this.inscriptionId();
    this.enregistrement.set(true);
    (id ? this.service.modifier(id, saisie) : this.service.creer(saisie)).subscribe({
      next: (detail) => {
        const photo = this.photo();
        if (!photo) {
          this.terminer(detail, !id);
          return;
        }
        this.service.envoyerPhoto(detail.id, photo).subscribe({
          next: (maj) => this.terminer(maj, !id),
          error: () => {
            this.notifier('Inscription enregistrée, mais la photo n\'a pas pu être envoyée.', true);
            this.terminer(detail, !id, false);
          },
        });
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrement.set(false);
        this.traiterErreurs(e);
      },
    });
  }

  private terminer(detail: DetailInscription, creation: boolean, notifier = true): void {
    this.enregistrement.set(false);
    if (notifier) {
      this.notifier(creation ? 'Inscription enregistrée.' : 'Modifications enregistrées.');
    }
    this.router.navigate(['/tabs/inscriptions', detail.id], { replaceUrl: true, state: { nouvelle: creation } });
  }

  private traiterErreurs(e: HttpErrorResponse): void {
    const brutes = (e.error?.errors ?? {}) as Record<string, string[]>;
    const erreurs: Record<string, string> = {};
    for (const [cle, messages] of Object.entries(brutes)) {
      erreurs[cle] = messages[0];
    }
    this.erreurs.set(erreurs);
    const cles = Object.keys(erreurs);
    if (!cles.length) {
      this.notifier(e.error?.message || 'Enregistrement impossible. Vérifiez votre connexion puis réessayez.', true);
      return;
    }
    // Retour a l'etape du premier champ en erreur.
    const premiere = cles[0];
    const etape: Etape = premiere.startsWith('eleve.') || premiere.startsWith('parents') ? 2 : premiere.startsWith('inscription.') || premiere.startsWith('precedente') ? 3 : 4;
    this.etape.set(this.modification() && etape < 2 ? 2 : etape);
    this.notifier(erreurs[premiere], true);
  }

  // ================================================================ Affichage

  erreur(cle: string): string | null {
    return this.erreurs()[cle] ?? null;
  }

  effacerErreur(cle: string): void {
    if (this.erreurs()[cle]) {
      const { [cle]: _, ...reste } = this.erreurs();
      this.erreurs.set(reste);
    }
  }

  montant(valeur: number | null | undefined): string {
    return (valeur ?? 0).toLocaleString('fr-FR') + ' F';
  }

  dateFr(date: string | null | undefined): string {
    if (!date) {
      return '';
    }
    const [a, m, j] = date.slice(0, 10).split('-');
    return `${j}/${m}/${a}`;
  }

  libelleSexe(sexe: string | null | undefined): string {
    return sexe === 'M' ? 'Masculin' : sexe === 'F' ? 'Féminin' : '';
  }

  libelleNiveau(id: number | null | undefined): string {
    return this.options()?.tous_niveaux.find((n) => n.id === id)?.libelle ?? '—';
  }

  libelleLien(lien: LienParente | null): string {
    return LIENS.find((l) => l.lien === lien)?.libelle ?? '—';
  }

  remplissage(c: ClasseInscription): number {
    return c.limite ? Math.min(100, Math.round((c.effectif / c.limite) * 100)) : 0;
  }

  valeurNombre(evenement: Event): number | null {
    const brut = (evenement.target as HTMLInputElement).value.replace(',', '.');
    return brut === '' || isNaN(Number(brut)) ? null : Number(brut);
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  // ================================================================ Interne

  private preparerSaisie(r: ResultatMatricule): void {
    this.erreurs.set({});
    if (r.cas === 'nouveau') {
      this.eleve.set(this.eleveVide(r.matricule));
      this.parents.set(LIENS.map((l) => this.parentVide(l.lien)));
      this.inscription.set(this.inscriptionVide());
      this.precedente.set(null);
      this.dettes.set([]);
      this.decision.set({ decision_finale: null, niveau_a_suivre_id: null, moyenne_annuelle: null });
      this.photoActuelle.set(null);
      return;
    }
    if (r.cas === 'reinscription') {
      this.remplirFiche(r.eleve);
      const prec = r.precedente;
      this.precedente.set(prec);
      this.dettes.set(r.dettes);
      this.decision.set({
        decision_finale: prec?.decision_finale ?? null,
        niveau_a_suivre_id: prec?.niveau_a_suivre_id ?? null,
        moyenne_annuelle: prec?.moyenne_annuelle ?? null,
      });
      this.inscription.set({
        ...this.inscriptionVide(),
        affecte: prec?.affecte ?? false,
        langue_vivante_2: prec?.langue_vivante_2 ?? null,
      });
      this.proposerNiveau();
    }
  }

  private remplirFiche(f: FicheEleve): void {
    this.eleve.set({
      matricule: f.matricule,
      nom: f.nom,
      prenoms: f.prenoms,
      sexe: f.sexe,
      date_naissance: f.date_naissance,
      lieu_naissance: f.lieu_naissance,
      nationalite: f.nationalite,
      telephone: f.telephone,
      quartier: f.quartier,
      particularites_medicales: f.particularites_medicales,
      orphelin_pere: f.orphelin_pere,
      orphelin_mere: f.orphelin_mere,
    });
    this.parents.set(LIENS.map((l) => ({ ...this.parentVide(l.lien), ...(f.parents.find((p) => p.lien_parente === l.lien) ?? {}) })));
    this.photoActuelle.set(f.photo_url ?? null);
  }

  private remplirDepuisDetail(d: DetailInscription): void {
    this.remplirFiche(d.eleve);
    this.matricule.set(d.eleve.matricule);
    this.precedente.set(d.precedente);
    this.dettes.set(d.dettes);
    this.classeInitiale.set(d.classe?.id ?? null);
    this.tarifInitial.set({ niveau: d.niveau?.id ?? null, affecte: d.affecte });
    this.modifiableTarif.set(d.modifiable_tarif);
    this.decision.set({
      decision_finale: d.precedente?.decision_finale ?? null,
      niveau_a_suivre_id: d.precedente?.niveau_a_suivre_id ?? null,
      moyenne_annuelle: d.precedente?.moyenne_annuelle ?? null,
    });
    this.inscription.set({
      niveau_id: d.niveau?.id ?? null,
      classe_id: d.classe?.id ?? null,
      affecte: d.affecte,
      redoublant: d.redoublant,
      boursier: d.boursier,
      langue_vivante_2: d.langue_vivante_2,
      etablissement_origine: d.etablissement_origine,
      classe_origine: d.classe_origine,
      decision_origine: d.decision_origine,
      moyenne_origine: d.moyenne_origine,
      inscrit_en_ligne: d.inscrit_en_ligne,
      inscription_en_ligne: d.inscription_en_ligne,
    });
  }

  private classesDe(niveauId: number): ClasseInscription[] {
    return this.niveauxAccessibles().find((n) => n.id === niveauId)?.classes ?? [];
  }

  private niveauSuivant(niveauId: number): number | null {
    const tous = [...(this.options()?.tous_niveaux ?? [])].sort((a, b) => a.ordre - b.ordre);
    const i = tous.findIndex((n) => n.id === niveauId);
    return i >= 0 && i < tous.length - 1 ? tous[i + 1].id : null;
  }

  private formatValide(matricule: string): boolean {
    const formats = this.options()?.formats_matricule ?? [];
    return formats.some((f) => new RegExp('^' + [...f].map((c) => (c === '9' ? '[0-9]' : c === 'A' ? '[A-Z]' : c.replace(/[.*+?^${}()|[\]\\\/-]/g, '\\$&'))).join('') + '$').test(matricule));
  }

  private eleveVide(matricule: string): ChampsEleve {
    return {
      matricule,
      nom: '',
      prenoms: '',
      sexe: null,
      date_naissance: null,
      lieu_naissance: null,
      nationalite: 'Ivoirienne',
      telephone: null,
      quartier: null,
      particularites_medicales: null,
      orphelin_pere: false,
      orphelin_mere: false,
    };
  }

  private parentVide(lien: LienParente): ParentEleve {
    return { lien_parente: lien, nom_complet: '', telephone: null, profession: null, is_contact_principal: false, is_payeur: false };
  }

  private inscriptionVide(): ChampsInscription {
    return {
      niveau_id: null,
      classe_id: null,
      affecte: false,
      redoublant: false,
      boursier: false,
      langue_vivante_2: null,
      etablissement_origine: null,
      classe_origine: null,
      decision_origine: null,
      moyenne_origine: null,
      inscrit_en_ligne: false,
      inscription_en_ligne: null,
    };
  }

  private libererApercuPhoto(): void {
    const url = this.apercuPhoto();
    if (url) {
      URL.revokeObjectURL(url);
    }
    this.apercuPhoto.set(null);
  }

  private premierMessage(e: HttpErrorResponse): string | null {
    const erreurs = e.error?.errors as Record<string, string[]> | undefined;
    return (erreurs && Object.values(erreurs)[0]?.[0]) || e.error?.message || null;
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}

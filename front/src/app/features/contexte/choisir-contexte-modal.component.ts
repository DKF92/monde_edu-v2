import {
  Component,
  DestroyRef,
  EventEmitter,
  Input,
  OnChanges,
  Output,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { IonModal, IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  calendarOutline,
  calendarClearOutline,
  businessOutline,
  briefcaseOutline,
  closeOutline,
  alertCircleOutline,
  logOutOutline,
} from 'ionicons/icons';
import { Subject, catchError, of, switchMap, tap } from 'rxjs';
import { AuthService } from '../../core/services/auth.service';
import { ContexteService } from '../../core/services/contexte.service';
import {
  ContexteEtablissement,
  Etablissement,
  PERMISSION_CHOIX_PERIODE,
  Periode,
} from '../../core/models/user.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';

/**
 * Modale "espace de travail" : annee scolaire + etablissement + poste, et
 * periode (trimestre/semestre) si le poste choisi a le droit
 * "periodes.choisir" (parametrable par poste). Sinon, la periode en cours
 * de l'annee est retenue automatiquement.
 *
 * - obligatoire=true (juste apres connexion, montee dans TabsPage) : aucun
 *   moyen de la fermer sans valider (pas de bouton annuler, pas de fermeture
 *   au clic sur le fond, pas de touche Echap). Seule autre issue : se
 *   deconnecter (utile si aucun poste/annee n'est configure).
 * - obligatoire=false (barre du haut, accueil, profil) : peut etre annulee
 *   sans rien changer.
 *
 * Un champ qui n'a qu'une valeur possible est affiche mais grise.
 */
@Component({
  selector: 'app-choisir-contexte-modal',
  standalone: true,
  imports: [IonModal, IonIcon, SelecteurComponent],
  templateUrl: './choisir-contexte-modal.component.html',
  styleUrl: './choisir-contexte-modal.component.scss',
})
export class ChoisirContexteModalComponent implements OnChanges {
  private readonly auth = inject(AuthService);
  private readonly contexteService = inject(ContexteService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  @Input() isOpen = false;
  @Input() obligatoire = true;
  @Output() ferme = new EventEmitter<void>();

  private readonly chargements = new Subject<number>();
  /** Annee voulue, conservee d'un etablissement a l'autre (les id different). */
  private libelleAnnee: string | undefined;

  readonly prenom = this.auth.prenom;

  readonly etablissementId = signal<number | null>(null);
  readonly anneeId = signal<number | null>(null);
  readonly posteId = signal<number | null>(null);
  readonly periodeId = signal<number | null>(null);

  readonly contexte = signal<ContexteEtablissement | null>(null);
  readonly chargement = signal(false);
  readonly erreur = signal(false);

  readonly optionsAnnees = computed<OptionSelecteur[]>(() =>
    (this.contexte()?.annees ?? []).map((a) => ({
      valeur: a.id,
      libelle: a.libelle,
      badge: a.is_active
        ? { texte: 'En cours', ton: 'succes' }
        : a.is_cloturee
          ? { texte: 'Clôturée', ton: 'neutre' }
          : null,
    })),
  );

  readonly optionsEtablissements = computed<OptionSelecteur[]>(() =>
    this.auth.etablissements().map((e) => ({ valeur: e.id, libelle: e.nom, detail: e.ville })),
  );

  readonly optionsPostes = computed<OptionSelecteur[]>(() =>
    (this.contexte()?.postes ?? []).map((p) => ({ valeur: p.id, libelle: p.nom })),
  );

  private readonly annee = computed(() => this.contexte()?.annees.find((a) => a.id === this.anneeId()));
  private readonly poste = computed(() => this.contexte()?.postes.find((p) => p.id === this.posteId()));

  /** Le champ Periode n'apparait que pour un poste qui a le droit de la choisir. */
  readonly afficherPeriode = computed(() => !!this.poste()?.permissions.includes(PERMISSION_CHOIX_PERIODE));

  readonly optionsPeriodes = computed<OptionSelecteur[]>(() =>
    (this.annee()?.periodes ?? []).map((p) => ({
      valeur: p.id,
      libelle: p.libelle,
      badge: p.is_active
        ? { texte: 'En cours', ton: 'succes' }
        : p.is_cloturee
          ? { texte: 'Clôturée', ton: 'neutre' }
          : null,
    })),
  );

  readonly peutValider = computed(
    () =>
      !this.chargement() &&
      !!this.contexte() &&
      !!this.anneeId() &&
      !!this.posteId() &&
      // Periode exigee seulement si le champ est affiche et qu'il y en a.
      (!this.afficherPeriode() || this.optionsPeriodes().length === 0 || !!this.periodeId()),
  );

  constructor() {
    addIcons({
      calendarOutline,
      calendarClearOutline,
      businessOutline,
      briefcaseOutline,
      closeOutline,
      alertCircleOutline,
      logOutOutline,
    });

    // Abonnement pose des le constructeur : ngOnChanges (qui declenche le
    // premier chargement) s'execute AVANT ngOnInit.
    this.chargements
      .pipe(
        tap(() => {
          this.chargement.set(true);
          this.erreur.set(false);
        }),
        // Changement d'etablissement rapide : seule la derniere reponse compte.
        switchMap((id) =>
          this.contexteService.charger(id).pipe(
            catchError(() => {
              this.erreur.set(true);
              return of(null);
            }),
          ),
        ),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((contexte) => {
        this.contexte.set(contexte);
        this.chargement.set(false);
        if (contexte) {
          this.preselectionner(contexte);
        }
      });
  }

  /**
   * IMPORTANT : Ionic applique canDismiss aussi bien aux fermetures
   * programmatiques (isOpen qui repasse a false) qu'aux gestes utilisateur
   * (backdrop, retour, swipe). En mode obligatoire, la fermeture n'est donc
   * autorisee qu'une fois l'espace de travail choisi (ou apres deconnexion),
   * ce qui correspond exactement au moment ou l'on veut qu'elle se ferme.
   * Elle se ferme aussi quand l'API exige le choix d'un mot de passe
   * (mot de passe provisoire) : cet ecran passe avant.
   */
  readonly peutFermer = async (): Promise<boolean> =>
    !this.obligatoire || this.auth.contexteComplet() || !this.auth.estConnecte() || this.auth.doitChangerMotDePasse();

  ngOnChanges(): void {
    // Recharge a chaque ouverture : entre deux ouvertures, l'utilisateur a pu
    // se deconnecter puis se reconnecter avec un autre compte (le composant,
    // lui, reste en cache dans Ionic).
    if (this.isOpen) {
      this.libelleAnnee = this.auth.anneeScolaire()?.libelle;
      this.contexte.set(null);
      this.anneeId.set(null);
      this.posteId.set(null);
      this.periodeId.set(null);
      const initial = this.etablissementInitial();
      if (initial) {
        this.choisirEtablissement(initial.id);
      }
    }
  }

  choisirEtablissement(id: number): void {
    this.etablissementId.set(id);
    this.chargements.next(id);
  }

  choisirAnnee(id: number): void {
    this.anneeId.set(id);
    this.libelleAnnee = this.annee()?.libelle;
    // Les periodes sont propres a chaque annee.
    this.periodeId.set(this.periodeParDefaut(this.annee()?.periodes ?? [])?.id ?? null);
  }

  reessayer(): void {
    const id = this.etablissementId();
    if (id) {
      this.chargements.next(id);
    }
  }

  valider(): void {
    const contexte = this.contexte();
    const annee = this.annee();
    const poste = this.poste();
    if (!contexte || !annee || !poste || !this.peutValider()) {
      return;
    }

    // Periode choisie si le poste y a droit ; sinon la periode en cours.
    const periode =
      (this.afficherPeriode() && annee.periodes.find((p) => p.id === this.periodeId())) ||
      annee.periodes.find((p) => p.is_active) ||
      annee.periodes[0] ||
      null;

    const { periodes: _, ...anneeScolaire } = annee;
    this.auth.appliquerContexte({ etablissement: contexte.etablissement, anneeScolaire, periode, poste });
    this.ferme.emit();
  }

  annuler(): void {
    if (!this.obligatoire) {
      this.ferme.emit();
    }
  }

  seDeconnecter(): void {
    this.auth.logout();
    this.router.navigateByUrl('/login', { replaceUrl: true });
  }

  /** Etablissement actif, sinon le dernier utilise, sinon le principal du compte. */
  private etablissementInitial(): Etablissement | undefined {
    const liste = this.auth.etablissements();
    const candidats = [
      this.auth.etablissementActif()?.id,
      this.auth.dernierContexte()?.etablissementId,
      this.auth.user()?.etablissement_id,
    ];
    for (const id of candidats) {
      const trouve = liste.find((e) => e.id === id);
      if (trouve) {
        return trouve;
      }
    }
    return liste[0];
  }

  private preselectionner(contexte: ContexteEtablissement): void {
    // Annee : la meme (par libelle) que celle voulue, sinon l'annee en cours,
    // sinon la plus recente.
    const annee =
      contexte.annees.find((a) => a.libelle === this.libelleAnnee) ??
      contexte.annees.find((a) => a.is_active) ??
      contexte.annees[0];
    this.anneeId.set(annee?.id ?? null);
    this.libelleAnnee = annee?.libelle;
    this.periodeId.set(this.periodeParDefaut(annee?.periodes ?? [])?.id ?? null);

    // Poste : l'actuel ou le dernier utilise s'il existe ici ; s'il n'y en a
    // qu'un, il est impose ; sinon l'utilisateur doit choisir.
    const candidats = [this.auth.poste()?.id, this.auth.dernierContexte()?.posteId];
    const poste =
      contexte.postes.find((p) => candidats.includes(p.id)) ??
      (contexte.postes.length === 1 ? contexte.postes[0] : undefined);
    this.posteId.set(poste?.id ?? null);
  }

  /** La periode actuelle si elle appartient a cette annee, sinon celle en
   * cours, sinon la premiere. */
  private periodeParDefaut(periodes: Periode[]): Periode | undefined {
    const actuelle = this.auth.periode();
    return (
      periodes.find((p) => p.id === actuelle?.id) ?? periodes.find((p) => p.is_active) ?? periodes[0]
    );
  }
}

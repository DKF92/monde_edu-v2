import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  radioButtonOn,
  radioButtonOffOutline,
  informationCircleOutline,
  cloudOfflineOutline,
  addOutline,
  closeOutline,
  checkmarkCircle,
  closeCircle,
  globeOutline,
  idCardOutline,
} from 'ionicons/icons';
import { ParametreService } from '../../core/services/parametre.service';
import { ParametresInscriptions, StatutVisibleClasse } from '../../core/models/parametre.model';

/**
 * Parametres des inscriptions :
 * - statut a partir duquel un eleve apparait dans sa classe (V1
 *   inscription.inscr_termine : 1 classe attribuee, 2 premier paiement, 3 solde) ;
 * - formats acceptes pour le matricule national (le ministere peut en changer) ;
 * - consultation de l'inscription en ligne sur le site de l'Etat.
 */
@Component({
  selector: 'app-parametres-inscriptions',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner],
  templateUrl: './parametres-inscriptions.page.html',
  styleUrls: ['./parametres.commun.scss', './parametres-inscriptions.page.scss'],
})
export class ParametresInscriptionsPage implements OnInit {
  private readonly service = inject(ParametreService);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly choix: { valeur: StatutVisibleClasse; titre: string; description: string; exemple: string; conseille?: boolean }[] = [
    {
      valeur: 'classe_attribuee',
      titre: "Dès l'ajout par l'éducateur",
      description: "L'élève apparaît dans la liste de sa classe dès que l'éducateur l'y a placé, même s'il n'est pas encore passé à la caisse.",
      exemple: 'Pratique pour faire l\'appel dès la rentrée ; la caisse suit les impayés à part.',
    },
    {
      valeur: 'premier_paiement',
      titre: 'Après un premier paiement',
      description: "L'élève n'apparaît dans la liste de sa classe qu'après au moins un paiement en caisse.",
      exemple: 'Fonctionnement actuel : oblige chaque élève à passer à la caisse.',
      conseille: true,
    },
    {
      valeur: 'solde',
      titre: 'Une fois les frais soldés',
      description: "L'élève n'apparaît dans la liste de sa classe que lorsque tout ce qu'il doit est payé (ou couvert par une réduction).",
      exemple: 'Le plus strict : réservé aux établissements qui exigent le paiement complet.',
    },
  ];

  readonly actuel = signal<ParametresInscriptions | null>(null);
  readonly choisi = signal<StatutVisibleClasse | null>(null);
  readonly formats = signal<string[]>([]);
  readonly verification = signal(true);
  readonly codeMena = signal('');

  readonly nouveauFormat = signal('');
  readonly erreurFormat = signal<string | null>(null);
  readonly essai = signal('');

  readonly chargement = signal(true);
  readonly erreur = signal(false);
  readonly enregistrement = signal(false);

  readonly modifie = computed(() => {
    const a = this.actuel();
    return (
      !!a &&
      (this.choisi() !== a.statut_visible_classe ||
        this.formats().join('|') !== a.formats_matricule.map((f) => f.format).join('|') ||
        this.verification() !== a.verification_en_ligne ||
        this.codeMena().trim() !== (a.code_mena ?? ''))
    );
  });

  /** Format reconnu pour le matricule d'essai. */
  readonly resultatEssai = computed(() => {
    const valeur = this.essai().trim().toUpperCase();
    if (!valeur) {
      return null;
    }
    return this.formats().find((f) => this.regex(f).test(valeur)) ?? false;
  });

  constructor() {
    addIcons({
      chevronBackOutline,
      radioButtonOn,
      radioButtonOffOutline,
      informationCircleOutline,
      cloudOfflineOutline,
      addOutline,
      closeOutline,
      checkmarkCircle,
      closeCircle,
      globeOutline,
      idCardOutline,
    });
  }

  ngOnInit(): void {
    this.charger();
  }

  charger(): void {
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.inscriptions().subscribe({
      next: (p) => {
        this.afficher(p);
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  annuler(): void {
    const a = this.actuel();
    if (a) {
      this.afficher(a);
    }
  }

  ajouterFormat(): void {
    const format = this.nouveauFormat().trim().toUpperCase();
    this.erreurFormat.set(null);
    if (!/^[9A\-\/]{3,20}$/.test(format) || !format.includes('9')) {
      this.erreurFormat.set('Utilisez 9 pour un chiffre et A pour une lettre (ex : AA999999), 3 à 20 caractères.');
      return;
    }
    if (this.formats().includes(format)) {
      this.erreurFormat.set('Ce format existe déjà.');
      return;
    }
    if (this.formats().length >= 10) {
      this.erreurFormat.set('10 formats au maximum.');
      return;
    }
    this.formats.update((f) => [...f, format]);
    this.nouveauFormat.set('');
  }

  retirerFormat(format: string): void {
    if (this.formats().length > 1) {
      this.formats.update((f) => f.filter((x) => x !== format));
    }
  }

  enregistrer(): void {
    const statut = this.choisi();
    if (!statut) {
      return;
    }
    this.enregistrement.set(true);
    this.service
      .enregistrerInscriptions({
        statut_visible_classe: statut,
        formats_matricule: this.formats(),
        verification_en_ligne: this.verification(),
        code_mena: this.codeMena().trim() || null,
      })
      .subscribe({
        next: (maj) => {
          this.enregistrement.set(false);
          this.afficher(maj);
          this.notifier('Paramètres enregistrés.');
        },
        error: (e: HttpErrorResponse) => {
          this.enregistrement.set(false);
          const erreurs = e.error?.errors as Record<string, string[]> | undefined;
          this.notifier((erreurs && Object.values(erreurs)[0]?.[0]) || 'Enregistrement impossible. Vérifiez votre connexion puis réessayez.', true);
        },
      });
  }

  /** "99999999A" -> "8 chiffres + 1 lettre" (meme regle que l'API). */
  description(format: string): string {
    return (format.match(/9+|A+|[^9A]+/g) ?? [])
      .map((g) => (g[0] === '9' ? `${g.length} chiffre${g.length > 1 ? 's' : ''}` : g[0] === 'A' ? `${g.length} lettre${g.length > 1 ? 's' : ''}` : `« ${g} »`))
      .join(' + ');
  }

  exemple(format: string): string {
    const chiffres = '1858339412345678';
    const lettres = 'PKABCDEFGH';
    let c = 0;
    let l = 0;
    return [...format].map((x) => (x === '9' ? chiffres[c++ % chiffres.length] : x === 'A' ? lettres[l++ % lettres.length] : x)).join('');
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private regex(format: string): RegExp {
    return new RegExp('^' + [...format].map((c) => (c === '9' ? '[0-9]' : c === 'A' ? '[A-Z]' : '\\' + c)).join('') + '$');
  }

  private afficher(p: ParametresInscriptions): void {
    this.actuel.set(p);
    this.choisi.set(p.statut_visible_classe);
    this.formats.set(p.formats_matricule.map((f) => f.format));
    this.verification.set(p.verification_en_ligne);
    this.codeMena.set(p.code_mena ?? '');
    this.erreurFormat.set(null);
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}

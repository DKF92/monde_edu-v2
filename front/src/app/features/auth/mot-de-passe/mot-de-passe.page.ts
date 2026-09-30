import { Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { IonContent, IonSpinner, IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { lockClosedOutline, keyOutline, eyeOutline, eyeOffOutline, alertCircleOutline, checkmarkCircle, ellipseOutline, arrowForwardOutline } from 'ionicons/icons';
import { AuthService } from '../../../core/services/auth.service';
import { AuthMarqueComponent } from '../../../shared/auth-marque.component';

type Champ = 'actuel' | 'nouveau' | 'confirmation';

/**
 * Premiere connexion avec un mot de passe provisoire (compte cree ou mot de
 * passe reinitialise par l'administration) : l'utilisateur choisit son
 * propre mot de passe avant d'acceder a l'application.
 */
@Component({
  selector: 'app-mot-de-passe',
  standalone: true,
  imports: [IonContent, IonSpinner, IonIcon, AuthMarqueComponent],
  templateUrl: './mot-de-passe.page.html',
  styleUrl: './mot-de-passe.page.scss',
})
export class MotDePassePage {
  readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly valeurs = signal<Record<Champ, string>>({ actuel: '', nouveau: '', confirmation: '' });
  readonly visible = signal(false);
  readonly tentative = signal(false);
  readonly chargement = signal(false);
  readonly erreurs = signal<Partial<Record<Champ, string>>>({});
  readonly erreur = signal<string | null>(null);

  /** Regles affichees sous le nouveau mot de passe (cochees au fil de la saisie). */
  readonly regles = computed(() => {
    const v = this.valeurs();
    return [
      { libelle: 'Au moins 8 caractères', ok: v.nouveau.length >= 8 },
      { libelle: 'Des lettres et des chiffres', ok: /[A-Za-z]/.test(v.nouveau) && /\d/.test(v.nouveau) },
      { libelle: 'Différent du mot de passe provisoire', ok: !!v.nouveau && v.nouveau !== v.actuel },
    ];
  });

  readonly controles = computed(() => {
    const v = this.valeurs();
    const e: Partial<Record<Champ, string>> = {};
    if (!v.actuel) e.actuel = 'Saisissez le mot de passe provisoire reçu.';
    if (!v.nouveau) e.nouveau = 'Choisissez votre nouveau mot de passe.';
    else if (this.regles().some((r) => !r.ok)) e.nouveau = 'Le mot de passe ne respecte pas les règles ci-dessous.';
    if (!v.confirmation) e.confirmation = 'Saisissez à nouveau le mot de passe.';
    else if (v.confirmation !== v.nouveau) e.confirmation = 'Les deux saisies ne sont pas identiques.';
    return e;
  });

  constructor() {
    addIcons({ lockClosedOutline, keyOutline, eyeOutline, eyeOffOutline, alertCircleOutline, checkmarkCircle, ellipseOutline, arrowForwardOutline });
  }

  saisir(champ: Champ, evenement: Event): void {
    const valeur = (evenement.target as HTMLInputElement).value;
    this.valeurs.update((v) => ({ ...v, [champ]: valeur }));
    this.erreurs.update((e) => ({ ...e, [champ]: undefined }));
    this.erreur.set(null);
  }

  erreurChamp(champ: Champ): string | null {
    return this.erreurs()[champ] ?? (this.tentative() ? this.controles()[champ] ?? null : null);
  }

  valider(): void {
    this.tentative.set(true);
    if (Object.keys(this.controles()).length) return;
    const v = this.valeurs();
    this.chargement.set(true);
    this.auth.changerMotDePasse(v.actuel, v.nouveau, v.confirmation).subscribe({
      next: () => {
        this.chargement.set(false);
        this.valeurs.set({ actuel: '', nouveau: '', confirmation: '' });
        // L'accueil affiche ensuite la modale de choix de l'espace de travail.
        this.router.navigateByUrl('/tabs/dashboard', { replaceUrl: true });
      },
      error: (e: HttpErrorResponse) => {
        this.chargement.set(false);
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        if (e.status === 422 && erreurs) {
          this.erreurs.set({ actuel: erreurs['actuel']?.[0], nouveau: erreurs['nouveau']?.[0] });
        } else {
          this.erreur.set(e.status === 0 ? 'Serveur injoignable. Vérifiez votre connexion internet puis réessayez.' : e.status === 429 ? 'Trop de tentatives. Patientez une minute avant de réessayer.' : 'Une erreur est survenue. Réessayez dans quelques instants.');
        }
      },
    });
  }

  seDeconnecter(): void {
    this.auth.logout();
    this.router.navigateByUrl('/login', { replaceUrl: true });
  }
}

import { Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { IonContent, IonSpinner, IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  mailOutline,
  lockClosedOutline,
  eyeOutline,
  eyeOffOutline,
  alertCircleOutline,
  logInOutline,
} from 'ionicons/icons';
import { AuthService } from '../../../core/services/auth.service';
import { AuthMarqueComponent } from '../../../shared/auth-marque.component';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [ReactiveFormsModule, IonContent, IonSpinner, IonIcon, AuthMarqueComponent],
  templateUrl: './login.page.html',
  styleUrl: './login.page.scss',
})
export class LoginPage {
  private readonly fb = inject(FormBuilder);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly chargement = signal(false);
  readonly erreur = signal<string | null>(null);
  readonly motDePasseVisible = signal(false);

  readonly formulaire = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
  });

  constructor() {
    addIcons({
      mailOutline,
      lockClosedOutline,
      eyeOutline,
      eyeOffOutline,
      alertCircleOutline,
      logInOutline,
    });
  }

  champInvalide(nom: 'email' | 'password'): boolean {
    const champ = this.formulaire.controls[nom];
    return champ.invalid && champ.touched;
  }

  seConnecter(): void {
    if (this.formulaire.invalid) {
      this.formulaire.markAllAsTouched();
      return;
    }

    this.chargement.set(true);
    this.erreur.set(null);

    const { email, password } = this.formulaire.getRawValue();

    this.auth.login(email!.trim(), password!).subscribe({
      next: (r) => {
        this.chargement.set(false);
        this.formulaire.controls.password.reset();
        // Mot de passe provisoire : l'utilisateur choisit d'abord le sien.
        // Sinon l'accueil affiche la modale de choix de l'espace de travail.
        this.router.navigateByUrl(r.user.doit_changer_mot_de_passe ? '/mot-de-passe' : '/tabs/dashboard', { replaceUrl: true });
      },
      error: (erreur: HttpErrorResponse) => {
        this.chargement.set(false);
        this.erreur.set(this.messageErreur(erreur));
      },
    });
  }

  private messageErreur(erreur: HttpErrorResponse): string {
    if (erreur.status === 0) {
      return 'Serveur injoignable. Vérifiez votre connexion internet puis réessayez.';
    }
    if (erreur.status === 422) {
      // Message metier renvoye par l'API ("Identifiants incorrects.", "Ce compte est desactive.").
      const message = erreur.error?.errors?.email?.[0];
      if (message && /desactive/i.test(message)) {
        return 'Ce compte est désactivé. Contactez l\'administration de votre établissement.';
      }
      return 'Adresse e-mail ou mot de passe incorrect.';
    }
    if (erreur.status === 429) {
      return 'Trop de tentatives. Patientez une minute avant de réessayer.';
    }
    return 'Une erreur est survenue. Réessayez dans quelques instants.';
  }
}

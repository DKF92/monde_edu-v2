import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import {
  IonContent,
  IonItem,
  IonInput,
  IonButton,
  IonText,
  IonSpinner,
  IonIcon,
  IonAvatar,
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import { schoolOutline, mailOutline, lockClosedOutline } from 'ionicons/icons';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [
    CommonModule,
    ReactiveFormsModule,
    IonContent,
    IonItem,
    IonInput,
    IonButton,
    IonText,
    IonSpinner,
    IonIcon,
    IonAvatar,
  ],
  templateUrl: './login.page.html',
  styleUrl: './login.page.scss',
})
export class LoginPage implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly etablissement = this.auth.etablissementConnexion;
  readonly chargement = signal(false);
  readonly erreur = signal<string | null>(null);

  readonly formulaire = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
  });

  constructor() {
    addIcons({ schoolOutline, mailOutline, lockClosedOutline });
  }

  ngOnInit(): void {
    // Impossible de se connecter sans savoir a quel etablissement on s'adresse.
    if (!this.etablissement()) {
      this.router.navigateByUrl('/');
    }
  }

  changerEtablissement(): void {
    this.router.navigateByUrl('/');
  }

  seConnecter(): void {
    if (this.formulaire.invalid) {
      this.formulaire.markAllAsTouched();
      return;
    }

    this.chargement.set(true);
    this.erreur.set(null);

    const { email, password } = this.formulaire.getRawValue();

    this.auth.login(email!, password!).subscribe({
      next: (reponse) => {
        this.chargement.set(false);
        if (reponse.etablissements.length > 1) {
          this.router.navigateByUrl('/select-etablissement');
        } else {
          this.router.navigateByUrl('/tabs/dashboard');
        }
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set('Email ou mot de passe incorrect.');
      },
    });
  }
}

import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { IonApp, IonRouterOutlet } from '@ionic/angular';
import { LoaderComponent } from './shared/loader.component';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, IonApp, IonRouterOutlet, LoaderComponent],
  templateUrl: './app.component.html',
})
export class AppComponent {}

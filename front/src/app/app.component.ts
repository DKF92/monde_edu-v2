import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { IonApp, IonRouterOutlet } from '@ionic/angular';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, IonApp, IonRouterOutlet],
  templateUrl: './app.component.html',
})
export class AppComponent {}

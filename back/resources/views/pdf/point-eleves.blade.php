{{-- Liste des eleves d'une case du point des inscrits : sans montants (demande du 06/10/2026),
     statut de l'inscription ; A4 portrait, une ligne par eleve (largeurs hors padding de 2 pt). --}}
@php($statuts = \App\Models\Inscription::LIBELLES_STATUT)
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $titre }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 10mm 10mm 12mm; }
  .liste { width: 100%; border-collapse: collapse; }
  .liste th { background: #eef2fa; color: #1b2f5e; font-size: 7pt; padding: 3.5pt 2pt; border: 0.5pt solid #d9deea; text-align: left; white-space: nowrap; }
  .liste td { font-size: 7pt; padding: 3pt 2pt; border: 0.5pt solid #e3e7f0; white-space: nowrap; }
  .liste .c { text-align: center; }
  .liste tr { page-break-inside: avoid; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => 'LISTE DES INSCRITS'])
  <h1>{{ $titre }}</h1>
  <div class="sous-titre">{{ $eleves->count() }} élève{{ $eleves->count() > 1 ? 's' : '' }} · édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>
  <table class="liste">
    <thead>
      <tr>
        <th class="c" style="width: 7mm">N°</th>
        <th style="width: 16mm">Matricule</th>
        <th>Nom et prénoms</th>
        <th class="c" style="width: 7mm">Sexe</th>
        <th style="width: 16mm">Classe</th>
        <th class="c" style="width: 11mm">Statut</th>
        <th class="c" style="width: 15mm">Inscrit le</th>
        <th style="width: 37mm">Inscription</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($eleves as $i => $e)
        <tr>
          <td class="c">{{ $i + 1 }}</td>
          <td>{{ $e['matricule'] }}</td>
          <td><strong>{{ $e['nom'] }}</strong> {{ \App\Support\Noms::ajuster((string) $e['nom'], $e['prenoms'], 67, 7, true) }}</td>
          <td class="c">{{ $e['sexe'] === 'F' ? 'F' : 'G' }}</td>
          <td>{{ \App\Support\Noms::couper($e['classe'] ?? $e['niveau'], 16, 7) }}</td>
          <td class="c">{{ $e['affecte'] ? 'AFF' : 'NAFF' }}</td>
          <td class="c">{{ \Carbon\Carbon::parse($e['date_inscription'])->format('d/m/Y') }}</td>
          <td>{{ $statuts[$e['statut']] ?? '' }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>

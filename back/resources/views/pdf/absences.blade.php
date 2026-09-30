@php($h = fn ($v) => fmod((float) $v, 1) ? number_format((float) $v, 1, ',', ' ') : number_format((float) $v, 0, ',', ' '))
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Absences</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 12mm 10mm 14mm; }
  h2 { font-size: 11pt; color: #1b2f5e; margin: 5mm 0 2mm; }
  .tab { width: 100%; border-collapse: collapse; }
  .tab th { background: #eef2fa; color: #1b2f5e; font-size: 7.2pt; text-transform: uppercase; padding: 4pt 3pt; border: 0.5pt solid #d9deea; text-align: left; }
  .tab td { font-size: 8.2pt; padding: 3pt; border: 0.5pt solid #e3e7f0; }
  .tab .d { text-align: right; } .tab .c { text-align: center; }
  .tab .total td { background: #d5dcef; font-weight: bold; }
  .tab tr { page-break-inside: avoid; }
  .non { color: #b3261e; font-weight: bold; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => 'ABSENCES'])
  <h1>Absences des élèves</h1>
  <div class="sous-titre">{{ $sous_titre ?: 'Toute l\'année' }} · {{ count($absences) }} absence{{ count($absences) > 1 ? 's' : '' }} · édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>

  <h2>Récapitulatif par élève</h2>
  <table class="tab">
    <tr><th>Classe</th><th>Matricule</th><th>Nom et prénoms</th><th class="d">Heures</th><th class="d">Justifiées</th><th class="d">Non justifiées</th></tr>
    @forelse ($par_eleve as $r)
      <tr>
        <td>{{ $r['classe'] }}</td><td>{{ $r['eleve']['matricule'] }}</td><td>{{ $r['eleve']['nom'] }} {{ $r['eleve']['prenoms'] }}</td>
        <td class="d">{{ $h($r['heures']) }}</td><td class="d">{{ $h($r['justifiees']) }}</td>
        <td class="d {{ $r['heures'] - $r['justifiees'] > 0 ? 'non' : '' }}">{{ $h($r['heures'] - $r['justifiees']) }}</td>
      </tr>
    @empty
      <tr><td colspan="6" class="c">Aucune absence.</td></tr>
    @endforelse
    <tr class="total"><td colspan="3">Total</td><td class="d">{{ $h(collect($par_eleve)->sum('heures')) }}</td><td class="d">{{ $h(collect($par_eleve)->sum('justifiees')) }}</td><td class="d">{{ $h(collect($par_eleve)->sum('heures') - collect($par_eleve)->sum('justifiees')) }}</td></tr>
  </table>

  <h2>Détail</h2>
  <table class="tab">
    <tr><th>Date</th><th>Classe</th><th>Nom et prénoms</th><th>Période</th><th class="d">Heures</th><th class="c">Justifiée</th><th>Motif</th></tr>
    @foreach ($absences as $a)
      <tr>
        <td>{{ \Illuminate\Support\Carbon::parse($a['date_debut'])->format('d/m/Y') }}{{ $a['date_fin'] && $a['date_fin'] !== $a['date_debut'] ? ' au '.\Illuminate\Support\Carbon::parse($a['date_fin'])->format('d/m/Y') : '' }}</td>
        <td>{{ $a['classe'] }}</td><td>{{ $a['eleve']['nom'] ?? '' }} {{ $a['eleve']['prenoms'] ?? '' }}</td><td>{{ $a['periode'] }}</td>
        <td class="d">{{ $h($a['nombre_heures']) }}</td><td class="c">{{ $a['is_justifiee'] ? 'Oui' : 'Non' }}</td><td>{{ $a['motif'] }}</td>
      </tr>
    @endforeach
  </table>
</body>
</html>

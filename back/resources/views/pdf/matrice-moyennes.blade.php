@php
  $n = fn ($v) => $v === null ? '' : number_format((float) $v, 2, ',', ' ');
  $rang = fn ($r, $ex = false) => $r ? $r.($r === 1 ? 'er' : 'e').($ex ? ' ex' : '') : '';
  $nbMatieres = count($matieres);
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Résultats {{ $classe->libelle }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 10mm 8mm 12mm; }
  .tab { width: 100%; border-collapse: collapse; }
  .tab th { background: #eef2fa; color: #1b2f5e; font-size: {{ $nbMatieres > 10 ? '5.8' : '6.6' }}pt; padding: 3pt 2pt; border: 0.5pt solid #d9deea; text-align: center; }
  .tab th small { display: block; font-weight: normal; color: #56607a; }
  .tab td { font-size: {{ $nbMatieres > 10 ? '6.8' : '7.6' }}pt; padding: 2.5pt 2pt; border: 0.5pt solid #e3e7f0; text-align: center; }
  .tab td.g { text-align: left; }
  .tab .fort { font-weight: bold; }
  .tab .faible { color: #b3261e; }
  .tab tr { page-break-inside: avoid; }
  .tab tr:nth-child(even) td { background: #fafbfd; }
  .stats { margin-top: 4mm; font-size: 8.5pt; color: #56607a; }
  .stats strong { color: #1d2433; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => 'RÉSULTATS DE CLASSE'])
  <h1>{{ $classe->libelle }} · {{ $periode_libelle }}</h1>
  <div class="sous-titre">Moyennes par matière (coefficient) · édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>

  <table class="tab">
    <thead>
      <tr>
        <th>N°</th>
        <th class="g">Élève</th>
        @foreach ($matieres as $m)<th>{{ $m['code'] }}<small>coef {{ $m['coefficient'] }}</small></th>@endforeach
        @if ($annuel)
          @foreach ($periodes as $p)<th>{{ $p['libelle'] }}<small>poids {{ $p['poids'] }}</small></th>@endforeach
        @else
          <th>Total<small>points</small></th>
          <th>Lettres</th>
          <th>Sciences</th>
        @endif
        <th>Moyenne</th>
        <th>Rang</th>
        <th class="g">Distinction / sanctions</th>
      </tr>
    </thead>
    <tbody>
      @foreach (collect($eleves)->sortBy(fn ($e) => [$e['rang'] ?? 9999, $e['nom']]) as $e)
        <tr>
          <td>{{ $loop->iteration }}</td>
          <td class="g"><strong>{{ $e['nom'] }}</strong> {{ $e['prenoms'] }}</td>
          @foreach ($matieres as $m)
            @php($v = $e['moyennes'][$m['id']]['moyenne'] ?? null)
            <td class="{{ $v !== null && $v < 10 ? 'faible' : '' }}">{{ $n($v) }}</td>
          @endforeach
          @if ($annuel)
            @foreach ($periodes as $p)<td>{{ $n($e['periodes'][$p['id']] ?? null) }}</td>@endforeach
          @else
            <td>{{ $n($e['total_points']) }}</td>
            <td>{{ $n($e['lettres']) }}</td>
            <td>{{ $n($e['sciences']) }}</td>
          @endif
          <td class="fort {{ $e['moyenne'] !== null && $e['moyenne'] < 10 ? 'faible' : '' }}">{{ $n($e['moyenne']) }}</td>
          <td>{{ $rang($e['rang'], $e['rang_ex_aequo'] ?? false) }}</td>
          <td class="g">{{ $e['distinction'] ?? implode(', ', $e['sanctions']) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  @php($s = $statistiques)
  <p class="stats">
    Effectif : <strong>{{ $s['effectif'] }}</strong> · classés : <strong>{{ $s['classes'] }}</strong> ·
    moyenne de la classe : <strong>{{ $n($s['moyenne_classe']) }}</strong> · plus forte : <strong>{{ $n($s['maximum']) }}</strong> ·
    plus faible : <strong>{{ $n($s['minimum']) }}</strong> · moyennes ≥ 10 : <strong>{{ $s['moyenne_10'] }}</strong>
    (filles {{ $s['filles']['admis'] }}, garçons {{ $s['garcons']['admis'] }}) · taux de réussite : <strong>{{ $s['taux_reussite'] !== null ? $n($s['taux_reussite']).' %' : '—' }}</strong>
  </p>
</body>
</html>

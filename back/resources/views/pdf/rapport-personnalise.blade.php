{{-- Rapport parametrable : colonnes choisies (champs ou colonnes libres a remplir), un tableau par groupe. --}}
@php
  $valeur = function ($ligne, $c) {
      $v = $ligne[$c['cle']] ?? null;
      return match ($c['type'] ?? 'texte') {
          'moyenne' => $v === null ? '' : number_format((float) $v, 2, ',', ' '),
          'montant' => $v === null ? '' : number_format((float) $v, 0, ',', ' '),
          'libre' => '',
          default => $v === null ? '' : $v,
      };
  };
  $classe = fn ($c) => match ($c['type'] ?? 'texte') { 'moyenne', 'montant', 'nombre' => 'd', 'centre' => 'c', 'libre' => 'libre', default => 'g' };
  $nb = count($rapport['colonnes']);
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $rapport['titre'] }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 12mm 10mm 14mm; }
  .groupe-page + .groupe-page { page-break-before: always; }
  .groupe-suite { margin-top: 6mm; }
  h2.groupe { font-size: 11pt; color: #1b2f5e; margin: 4mm 0 1mm; }
  .effectif { font-size: 8pt; color: #56607a; margin-bottom: 1.5mm; }
  .tab { width: 100%; border-collapse: collapse; }
  .tab th { background: #eef2fa; color: #1b2f5e; font-size: {{ $nb > 9 ? '6.4' : '7.4' }}pt; text-transform: uppercase; padding: 4pt 3pt; border: 0.5pt solid #b9c2d6; }
  .tab td { font-size: {{ $nb > 9 ? '7.4' : '8.6' }}pt; padding: 4pt 3pt; border: 0.5pt solid #b9c2d6; }
  .tab .g { text-align: left; } .tab .c { text-align: center; } .tab .d { text-align: right; }
  .tab td.libre { min-width: 22mm; }
  .tab tr { page-break-inside: avoid; }
</style>
</head>
<body>
@foreach ($rapport['groupes'] as $g)
  <div class="{{ $rapport['saut_page'] ? 'groupe-page' : ($loop->first ? '' : 'groupe-suite') }}">
    @if ($loop->first || $rapport['saut_page'])
      @include('pdf._entete-caisse', ['titreDocument' => mb_strtoupper($rapport['titre'])])
      @if ($loop->first)
        <div class="sous-titre" style="margin-top: 2mm">{{ $rapport['sous_titre'] }} · Édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>
      @endif
    @endif
    @if ($g['titre'])<h2 class="groupe">{{ $g['titre'] }}</h2>@endif
    <div class="effectif">Effectif : <strong>{{ $g['effectif'] }}</strong> · Filles : {{ $g['filles'] }} · Garçons : {{ $g['garcons'] }}</div>
    <table class="tab">
      <thead>
        <tr>@foreach ($rapport['colonnes'] as $c)<th class="{{ $classe($c) === 'libre' ? 'c' : $classe($c) }}">{{ $c['libelle'] }}</th>@endforeach</tr>
      </thead>
      <tbody>
        @foreach ($g['lignes'] as $l)
          <tr>@foreach ($rapport['colonnes'] as $c)<td class="{{ $classe($c) }}">{{ $valeur($l, $c) }}</td>@endforeach</tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endforeach
@if (!count($rapport['groupes']))
  @include('pdf._entete-caisse', ['titreDocument' => mb_strtoupper($rapport['titre'])])
  <p class="sous-titre" style="margin-top: 6mm">Aucun élève ne correspond aux critères du rapport.</p>
@endif
</body>
</html>

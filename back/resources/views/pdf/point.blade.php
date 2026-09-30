{{-- Point des inscrits / des dettes : sections lignes x affectes / non affectes / total (App\Support\Points). --}}
@php
  $m = fn ($v) => number_format((int) $v, 0, ',', "\u{202F}");
  $v = fn ($ligne, $c) => ($ligne['unite'] ?? $p['unite']) === 'montant' ? $m($ligne[$c]).' F' : $m($ligne[$c]);
  $annee = $p['annee'];
  // Impression generale : sections regroupees en grandes parties numerotees
  // (ex. « 2. Total inscrits par genre » : filles, garcons).
  $parties = collect($p['sections'])->groupBy(fn ($s) => $s['partie'] ?? $s['titre'])->values();
  $general = $parties->count() > 1;
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $titreDocument }}</title>
@include('pdf._style-caisse')
<style>
  .section { page-break-inside: avoid; margin-bottom: 6mm; }
  .section h2 { font-size: 10.5pt; color: #1b2f5e; margin: 0 0 1mm; }
  .section .note { font-size: 8pt; color: #56607a; margin-bottom: 2mm; }
  .partie { font-size: 12pt; color: #1b2f5e; text-transform: uppercase; margin: 7mm 0 3mm; padding-bottom: 2pt; border-bottom: 1.2pt solid #1b2f5e; page-break-after: avoid; }
  .partie-bloc:first-of-type .partie { margin-top: 3mm; }
  .sommaire { font-size: 8.5pt; color: #56607a; margin: 0 0 3mm; }
  .general .section h2 { font-size: 10pt; color: #1d2433; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => mb_strtoupper($titreDocument)])

  <h1>{{ $p['titre'] }}</h1>
  <div class="sous-titre">
    @if (!empty($p['caissier'])) Caissier : {{ $p['caissier'] }} · @endif
    Édité le {{ \Carbon\Carbon::parse($p['genere_le'])->format('d/m/Y à H:i') }} par {{ $edite_par }}
  </div>

  @if ($general)
    <p class="sommaire">@foreach ($parties as $i => $sections){{ $i + 1 }}. {{ $sections[0]['partie'] ?? $sections[0]['titre'] }}@if (! $loop->last) · @endif @endforeach</p>
  @endif

  @foreach ($parties as $i => $sections)
  <div class="partie-bloc {{ $general ? 'general' : '' }}">
  @if ($general)
    <h2 class="partie">{{ $i + 1 }}. {{ $sections[0]['partie'] ?? $sections[0]['titre'] }}</h2>
  @endif
  @foreach ($sections as $s)
    <div class="section">
      {{-- Partie d'une seule section du meme nom : le grand titre suffit. --}}
      @if (! $general || count($sections) > 1 || ! str_starts_with($s['titre'], $s['partie'] ?? $s['titre']))
        <h2>{{ $s['titre'] }}</h2>
      @elseif (str_contains($s['titre'], ' · '))
        <div class="note">{{ \Illuminate\Support\Str::after($s['titre'], ' · ') }}</div>
      @endif
      @if (!empty($s['sous_titre']))<div class="note">{{ $s['sous_titre'] }}</div>@endif
      <table class="bilan">
        <tr><th></th>@foreach ($p['colonnes'] as $libelle)<th>{{ $libelle }}</th>@endforeach</tr>
        @forelse ($s['lignes'] as $l)
          <tr>
            <td class="libelle">{{ $l['libelle'] }}</td>
            @foreach ($p['colonnes'] as $c => $libelle)
              <td class="{{ $c === 'total' ? 'colonne-total' : '' }}">{{ $v($l, $c) }}</td>
            @endforeach
          </tr>
        @empty
          <tr><td colspan="{{ count($p['colonnes']) + 1 }}" style="text-align: center; color: #8a93a6">Aucune donnée sur la période.</td></tr>
        @endforelse
        @if ($s['total'])
          <tr class="ligne-total">
            <td>{{ $s['total']['libelle'] }}</td>
            @foreach ($p['colonnes'] as $c => $libelle)
              <td>{{ $v($s['total'], $c) }}{{ $c === 'total' && $p['unite'] === 'eleves' ? ' élève'.($s['total'][$c] > 1 ? 's' : '') : '' }}</td>
            @endforeach
          </tr>
        @endif
      </table>
    </div>
  @endforeach
  </div>
  @endforeach

  <table class="pied">
    <tr>
      <td class="signature" style="text-align: left"><span>Établi par · {{ $edite_par }}</span></td>
      <td class="signature"><span>Visa du responsable</span></td>
    </tr>
  </table>
</body>
</html>

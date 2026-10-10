{{-- Statistiques du reste a payer / des dettes (ResteAPayer::statistiques), avant la liste :
     un tableau pleine largeur par regroupement (genre, statut, redoublement, cycle, niveau, classe),
     nombres d'eleves par etape puis montants. --}}
@php($mStat = fn ($v) => number_format((int) $v, 0, ',', "\u{202F}"))
<style>
  .stats-titre { font-size: 10pt; color: #1b2f5e; margin: 4mm 0 2mm; }
  .bloc-stats { page-break-inside: avoid; margin-bottom: 4mm; }
  .bloc-stats h3 { font-size: 8.5pt; color: #1d2433; margin: 0 0 1mm; }
  .stats { width: 100%; border-collapse: collapse; }
  .stats th { background: #eef2fa; color: #1b2f5e; font-size: 7pt; padding: 3pt 3pt; border: 0.5pt solid #d9deea; text-align: right; white-space: nowrap; }
  .stats td { font-size: 7pt; padding: 2.5pt 3pt; border: 0.5pt solid #e3e7f0; text-align: right; white-space: nowrap; }
  .stats th.g, .stats td.g { text-align: left; }
  .stats tr.total td { font-weight: bold; background: #f2f5fb; }
  .stats .reste { color: #b35c00; font-weight: bold; }
  .apres-stats { page-break-after: always; }
</style>
<h2 class="stats-titre">Statistiques · {{ $nombre }} élève{{ $nombre > 1 ? 's' : '' }}</h2>
@foreach ($statistiques as $s)
  <div class="bloc-stats">
    <h3>{{ $s['titre'] }}</h3>
    <table class="stats">
      <tr>
        <th class="g">{{ \Illuminate\Support\Str::ucfirst(mb_substr($s['titre'], 4)) }}</th>
        @foreach ($s['colonnes'] as $libelle)<th>{{ $libelle }}</th>@endforeach
        <th>Élèves</th>
        @foreach ($s['montants'] as $libelle)<th>{{ $libelle }}</th>@endforeach
      </tr>
      @foreach ($s['lignes'] as $r)
        <tr class="{{ $loop->last ? 'total' : '' }}">
          <td class="g">{{ \App\Support\Noms::couper($r['libelle'], 45, 7) }}</td>
          @foreach ($r['valeurs'] as $v)<td>{{ $v }}</td>@endforeach
          <td>{{ $r['total'] }}</td>
          @foreach ($r['montants'] as $cle => $v)<td class="{{ $loop->last ? 'reste' : '' }}">{{ $mStat($v) }}</td>@endforeach
        </tr>
      @endforeach
    </table>
  </div>
@endforeach
<div class="apres-stats"></div>

{{-- En-tete commun a tous les documents : a gauche la tutelle (ministere, direction
     regionale, adresse postale, telephone), a droite le logo, le nom de l'ecole et
     l'annee scolaire ; puis le titre du document. Donnees : Document::entete(). --}}
@php
  $tutelle = $tutelle ?? [];
  $anneeDoc = $annee ?? $b['annee'] ?? $anneeEntete ?? null;
@endphp
  <table class="entete">
    <tr>
      <td class="tutelle">
        @if (!empty($tutelle['ministere']))<div class="ministere">{{ $tutelle['ministere'] }}</div><div class="trait">————</div>@endif
        @if (!empty($tutelle['direction']))<div class="direction">{{ $tutelle['direction'] }}</div>@endif
        @if (!empty($tutelle['adresse']))<div class="ligne-tutelle">{{ $tutelle['adresse'] }}</div>@endif
        @if (!empty($tutelle['telephone']))<div class="ligne-tutelle">{{ $tutelle['telephone'] }}</div>@endif
      </td>
      <td class="ecole">
        @if ($logo)<img class="logo" src="{{ $logo }}" alt=""><br>@endif
        <div class="nom-etab">{{ $etablissement->nom }}</div>
        @if ($anneeDoc)<div class="annee-doc">Année scolaire {{ $anneeDoc }}</div>@endif
      </td>
    </tr>
  </table>
  <div class="bande"></div>
  @if (!empty($titreDocument))
    <div class="titre-doc">{{ $titreDocument }}</div>
  @endif

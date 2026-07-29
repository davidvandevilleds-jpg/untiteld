# Photo Print Studio

WordPress-plugin met een stapsgewijze bestelwizard voor foto's op maat laten
printen: tot 10 foto's tegelijk uploaden, formaat/DPI-controle, een
standaardformaat of een zelf ingegeven aangepast formaat kiezen (geldt voor
de hele set), indien nodig per foto bijsnijden en 90° draaien in een
interactieve crop-tool, papierkeuze (Hahnemühle Digital FineArt Collection),
montage op Dibond met afwerkingen, aantal exemplaren per foto kiezen, en
afronden via WooCommerce.

## Installatie

1. Kopieer de map `photo-print-studio` naar `wp-content/plugins/`.
2. Activeer **Photo Print Studio** in wp-admin → Plugins. Bij activatie
   wordt een startcatalogus aangemaakt (standaardformaten, een selectie
   Hahnemühle-papieren, de twee montage-opties en de afwerkingsopties).
3. Installeer en activeer **WooCommerce** (vereist om te kunnen afrekenen —
   zonder WooCommerce werkt de catalogus/instellingen wel, maar kan er niet
   besteld worden).
4. Plaats de shortcode `[photo_print_wizard]` op een pagina (bv. "Bestel je
   print").

## Producten toevoegen en prijzen aanpassen

Alles wordt beheerd via het menu **Print Studio** in wp-admin — geen code
nodig:

| Onderdeel | Menu | Belangrijkste velden |
|---|---|---|
| Formaten | Print Studio → Formaten | Breedte/hoogte (cm), optionele vaste toeslag (naast de standaardformaten kan de klant in de wizard ook zelf een aangepast formaat ingeven) |
| Papieren | Print Studio → Papieren (Hahnemühle) | Prijs per m² |
| Montages | Print Studio → Montage-opties | Prijs per m², "Vraagt om een afwerkingskeuze" |
| Afwerkingen | Print Studio → Afwerkingen (Dibond) | Prijs per lopende meter (lm), per m² en/of vaste prijs |

Een nieuw item toevoegen werkt als een gewone WordPress-pagina: titel,
eventueel een beschrijving en uitgelichte afbeelding (getoond in de
wizard), en de prijsvelden in het vak eronder. Verwijderen of naar de
prullenbak verplaatsen haalt een optie uit de wizard.

Wil je bijvoorbeeld canvas of plexiglas toevoegen als nieuwe montage? Voeg
gewoon een nieuwe "Montage-optie" toe met zijn eigen prijs per m² — de
wizard toont hem automatisch.

Globale instellingen (minimale DPI, maximale afmetingen voor een aangepast
formaat, behandelingskost, e-mailadres voor bestelmeldingen) staan onder
**Print Studio → Instellingen**.

## Meerdere foto's in één bestelling

Een klant kan tot 10 foto's uploaden in stap 1 (thumbnails met een
verwijderknop verschijnen onderaan). Formaat, papier, montage en afwerking
worden **één keer** gekozen en gelden voor de hele set; per foto kies je
enkel het **aantal exemplaren**. In het overzicht (laatste stap) ziet de
klant een regel per foto (aantal × prijs per stuk) plus het totaal. Bij het
afronden van de bestelling komt elke foto als aparte regel in de
WooCommerce-bestelling terecht, met haar eigen aantal en eigen crop/foto.

## Crop-tool

Omdat elke foto zijn eigen resolutie en beeldverhouding heeft, wordt de
DPI/verhouding-controle per foto uitgevoerd, ook al is het gekozen formaat
voor iedereen hetzelfde. Zodra een foto niet overeenkomt met het gekozen
formaat, of waarbij de effectieve resolutie onder de ingestelde minimale
DPI zakt, verschijnt bij die foto automatisch een waarschuwing en een knop
om de interactieve uitsnede-tool te openen:

De klant ziet steeds de volledige foto; daarover ligt een kader in de
verhouding van het gekozen formaat (het gedeelte buiten het kader is
gedimd):

- **Verschuiven**: sleep het kader over de foto om te kiezen wat wordt
  afgedrukt.
- **Zoomen**: schuifregelaar om het kader kleiner (verder ingezoomd) of
  groter te maken.
- **90° draaien**: knop om de foto in stappen van 90° te roteren, handig
  wanneer een liggende foto op een staand formaat (of omgekeerd) moet
  passen.

Ook wanneer een foto al perfect past, kan de klant via de knop "Uitsnede
zelf aanpassen" bij die foto de tool altijd zelf openen om de compositie
naar wens bij te stellen. Er is telkens maar één uitsnede-editor
tegelijk open.

De uiteindelijke uitsnede (positie, grootte én rotatiehoek) wordt per foto
opgeslagen bij de bestelling en is zichtbaar op het bestellingsscherm in
wp-admin, naast de downloadlink naar de originele foto.

## Fotominiatuur bij het product

Omdat elke bestelling via één en hetzelfde ("verborgen") WooCommerce-product
verloopt, toont de winkelmand/bestelpagina standaard geen productfoto. Deze
plugin toont in de plaats daarvan een kleine miniatuur van de eigen
geüploade foto van de klant — zichtbaar in het winkelmandje, bij het
afrekenen (besteloverzicht), op de bevestigingspagina, in de
bestelmails en onder Mijn account → Bestellingen.

## E-mails bij bestelling

- **Wij (order@bunker.gallery, aanpasbaar onder Instellingen)** ontvangen
  WooCommerce's "Nieuwe bestelling"-mail, aangevuld met de originele,
  hoge-resolutie foto('s) als bijlage plus alle keuzes (formaat, papier,
  montage, afwerking) in het overzicht. Bestanden groter dan 15 MB worden
  niet bijgevoegd (mailservers wijzen grote bijlagen vaak af) — die foto
  blijft wel altijd rechtstreeks downloadbaar via de bestelling in
  wp-admin.
- **De klant** ontvangt automatisch WooCommerce's eigen
  bevestigingsmail (bv. "Bestelling in verwerking" / "Bestelling
  voltooid") met hetzelfde overzicht van formaat, papier, montage en
  afwerking.

Dit gebruikt de bestaande WooCommerce e-mailinstellingen (WooCommerce →
Instellingen → E-mails) voor afzender, huisstijl en het aan/uit zetten van
losse mails — deze plugin voegt enkel de foto-bijlage en het gegarandeerde
order@bunker.gallery-adres toe.

## Upload van grote foto's

Foto's worden in kleine stukjes (chunks van 2 MB) naar de server gestuurd
in plaats van in één keer. Zo loopt een upload niet vast op de
`upload_max_filesize`/`post_max_size`-limiet van de hosting (een
serverinstelling die vanuit de plugin niet kan worden aangepast) — dit
loste een fout op waarbij foto's groter dan enkele MB werden geweigerd.
Er is geen configuratie nodig; dit werkt automatisch, tot de ingestelde
maximum bestandsgrootte (standaard 200 MB, aan te passen via het
`pps_max_upload_bytes`-filter).

## Prijsberekening

```
omtrek (lm)  = 2 × (breedte_m + hoogte_m)
oppervlakte  = breedte_m × hoogte_m

prijs per stuk = oppervlakte × (papier €/m² + montage €/m²)
                + afwerking (omtrek × €/lm + oppervlakte × €/m² + vaste prijs, elk optioneel)
                + vaste toeslag van het gekozen formaat (optioneel)

bestelling totaal = Σ (prijs per stuk × aantal exemplaren, per foto)
                   + vaste behandelingskost (instellingen, éénmalig per bestelling)
```

De prijs per lopende meter (lm) is bedoeld voor afwerkingen die per lengte
verkocht worden, zoals randafwerking (zilver/goud/koper) en aluminium
frames: de kost is de omtrek van het gekozen formaat in lopende meter,
maal de ingestelde lm-prijs. Bij activatie van de plugin zijn de
"randafwerking"- en "aluminium frame"-afwerkingen al met een lm-prijs
ingesteld; pas dit aan onder **Print Studio → Afwerkingen (Dibond)**.

De behandelingskost wordt precies één keer per bestelling aangerekend (als
WooCommerce-"kost" op het winkelmandje), ongeacht hoeveel foto's of
exemplaren er besteld worden.

## Stijl

De wizard-CSS (`assets/css/wizard.css`) gebruikt CSS-variabelen bovenaan
(`--pps-color-*`, `--pps-font-*`) zodat de kleuren en typografie in één
plek aangepast kunnen worden om exact aan te sluiten bij het thema van
www.bunker.gallery.

## Technisch

- Vier custom post types (`pps_format`, `pps_paper`, `pps_mount`,
  `pps_finish`) vormen de catalogus.
- REST-routes onder `pps/v1` verzorgen upload, DPI-controle, prijsopvraag
  en "toevoegen aan winkelmand".
- Eén verborgen WooCommerce-product ("Foto print op maat") vertegenwoordigt
  elke foto in de winkelmand (één regel per foto, met haar eigen aantal);
  de effectieve prijs per stuk en alle keuzes (formaat, papier, montage,
  afwerking, crop, originele foto) worden per winkelmand-item/
  bestellingsregel bijgehouden. Op het bestellingsscherm in wp-admin
  verschijnt per foto een downloadlink naar de originele, hoge-resolutie
  versie plus de toegepaste uitsnede/rotatie.

# Photo Print Studio

WordPress-plugin met een stapsgewijze bestelwizard voor foto's op maat laten
printen: uploaden, formaat/DPI-controle met crop-voorbeeld, papierkeuze
(Hahnemühle Digital FineArt Collection), montage op Dibond met afwerkingen,
en afronden via WooCommerce.

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
| Formaten | Print Studio → Formaten | Breedte/hoogte (cm), optionele vaste toeslag |
| Papieren | Print Studio → Papieren (Hahnemühle) | Prijs per m² |
| Montages | Print Studio → Montage-opties | Prijs per m², "Vraagt om een afwerkingskeuze" |
| Afwerkingen | Print Studio → Afwerkingen (Dibond) | Prijs per m² en/of vaste prijs |

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

## Prijsberekening

```
totaal = (breedte_m × hoogte_m) × (papier €/m² + montage €/m²)
       + afwerking (€/m² × oppervlakte + vaste prijs, indien van toepassing)
       + vaste toeslag van het gekozen formaat (optioneel)
       + vaste behandelingskost (instellingen)
```

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
  elke bestelling in de winkelmand; de effectieve prijs en alle keuzes
  (formaat, papier, montage, afwerking, crop, originele foto) worden per
  winkelmand-item/bestellingsregel bijgehouden. Op het bestellingsscherm in
  wp-admin verschijnt een downloadlink naar de originele, hoge-resolutie
  foto.

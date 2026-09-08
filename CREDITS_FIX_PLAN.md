# Technisch herstelplan: Customers & Credits

Datum: 2026-09-08  
Status: implementatie-instructie; fixes nog niet uitgevoerd.

## Doel en scope

Herstel de huidige uncommitted implementatie van Customers & Credits in SprintManager. Behoud bestaande functionaliteit en lokale wijzigingen. Corrigeer de creditberekeningen, entiteitsafbakening, rechten en inconsistenties tussen invoer, rapportages en exports. Voeg gerichte gedragstests toe en controleer de migratie.

Dit document beschrijft de gewenste implementatie en acceptatiecriteria. Het is geen verklaring dat de huidige implementatie al aan deze eisen voldoet. Voer geen automatische commit uit.

## 1. Bevindingen uit de review

| Prioriteit | Bevinding | Betrokken code |
| --- | --- | --- |
| Hoog | Verval wordt berekend uit globale totalen, zonder toewijzing van verbruik aan aankopen. Hierdoor kan een nieuwe aankoop onterecht worden verminderd door eerder vervallen credits. | `src/SprintCustomer.php`: `purchasedByCustomer()`, `balances()` |
| Hoog | Aankopen met een toekomstige boekingsdatum tellen direct mee in het huidige saldo. | `src/SprintCustomer.php`: `purchasedByCustomer()` |
| Hoog | Retainers worden gecombineerd met sprints uit ongerelateerde entiteiten. De automatische snapshot verwerkt eveneens klanten zonder sprintentiteitscontrole. | `src/SprintCustomer.php`: `sprintFunding()`, `snapshotAgreements()` |
| Hoog | De backlogmatrix gebruikt standaardafspraken in plaats van de effectieve sprintoverride en het retainerdatumvenster. | `src/Backlog.php`: `renderCreditMatrixFragment()` |
| Midden | Quick-edit kan een bestaande inactieve klant wissen: de optie ontbreekt en de lege selectie wordt als klant-ID `0` verstuurd. | `src/Backlog.php`: `renderEditModal()`; gedeelde quick-edit in `src/SprintItem.php` en meetingtemplates controleren |
| Midden | Forecast verrekent ongebruikte ruimte in de ene sprint met overschrijding in een andere sprint. | `src/SprintCredits.php`: `renderForecast()`, `advice()` |
| Midden | Geparkeerde items worden uitgesloten in de matrix, maar blijven meetellen in creditaggregaties. | `src/SprintCustomer.php`: `usageByCustomer()`, `usageBySprint()` |
| Midden | Aanmaken en permanent verwijderen van creditboekingen gebruiken het UPDATE-recht, ondanks afzonderlijke CREATE/PURGE-profielrechten. | `src/SprintCredit.php`; `front/sprintcredit.form.php` |
| Midden | Niet alle publieke aggregaties weigeren zelfstandig zonder credit-READ; `sprintFunding()` kan grantbedragen teruggeven. | `src/SprintCustomer.php` |

De walletfout, toekomstige boekingen, entiteitsoverschrijdende grant en foutieve forecast zijn tijdens de review gereproduceerd met de huidige PHP-methoden en testdata met vervangende GLPI/database-afhankelijkheden. Dit was geen volledige GLPI-integratietest.

## 2. Uitvoeringsvolgorde

1. Leg de financiële begrippen, statusovergangen en datumregels vast.
2. Voeg gedragstests toe die de geconstateerde fouten reproduceren.
3. Introduceer gedeelde berekeningen en herstel entiteitscontroles.
4. Herstel aankoopallocatie, verval, correcties en reserveringen.
5. Herstel sprintafspraken en historische snapshots.
6. Sluit matrix, creditpagina, forecast en CSV aan op dezelfde resultaten.
7. Herstel klantselectie en rechten op alle invoerpaden.
8. Optimaliseer queries, verwijder resten en actualiseer documentatie.
9. Controleer installatie, upgrade, statische checks en GLPI-gebruikersflows.

Beperk wijzigingen tot deze scope. Vermijd omvangrijke algemene refactors die niet nodig zijn voor correcte creditverwerking.

## 3. Domeinregels en begrippen

| Begrip | Betekenis |
| --- | --- |
| Grant / sprintafspraak | Credits toegekend aan één klant voor één specifieke sprint. |
| Consumed / verbruikt | Credits voor daadwerkelijk afgerond werk. |
| Reserved / gereserveerd | Credits voor onafgerond werk dat daadwerkelijk aan een sprint is toegewezen. |
| Pipeline / backlog | Werk dat nog niet aan een sprint is toegewezen, ook als een doelsprint is voorgesteld. |
| Wallet balance / walletsaldo | Geldige aangekochte credits na werkelijk verbruik, correcties en verval. |
| Wallet reserved / walletreservering | Nog niet verbruikte walletdekking die toegewezen werk nodig heeft boven de sprintafspraak. |
| Wallet available / vrij beschikbaar | Walletsaldo minus walletreserveringen. |
| Lapsed / vervallen | Ongebruikte credits waarvan de geldigheid voorbij is. |

### Invarianten

- Een sprintafspraak kan alleen worden gebruikt binnen die sprint. Er vindt geen saldering van grants tussen sprints plaats.
- Verbruik en reserveringen zijn verschillende grootheden. Een reservering is geen historisch verbruik.
- Voorgesteld backlogwerk telt mee in een planningsprognose, maar niet automatisch als definitieve reservering.
- Een toekomstige reservering voorkomt niet dat ongebruikte aankoopcredits vandaag vervallen.
- Alleen een op de effectieve verbruiksdatum geldige aankoop kan dat verbruik dekken.
- Een mutatie mag niet tweemaal worden afgeboekt door herhaalde verwerking.
- Een wijziging van standaardafspraken mag historische snapshots niet veranderen.
- Bedragen moeten op twee decimalen consistent worden berekend en afgerond. Gebruik bij voorkeur integer-honderdsten voor interne rekenfuncties; houd de bestaande DECIMAL-opslag waar passend.

### Eerst expliciet vastleggen

Onderzoek de bestaande item- en sprintflows en documenteer de gekozen verwerking van:

- Afsluiten van een sprint met onafgeronde items.
- Carry-over: het bronitem, de nieuwe kopie en het vrijvallen van reserveringen.
- Heropenen van een afgerond item of een afgesloten sprint.
- Wijzigen van klant of credits nadat werk is afgerond.
- Een sprint annuleren en eerder geregistreerd verbruik.
- Negatieve boekingen, backdated correcties en eventueel toegestane negatieve saldi.
- De effectieve datum van werkelijk verbruik en de betekenis van de vervaldatum.

Gebruik de bestaande code om de flows te begrijpen, maar neem huidige rekenfouten niet over als bedrijfsregel. Leg eventuele resterende productbeslissingen concreet vast voordat afhankelijke datamigraties worden uitgevoerd.

## 4. Centrale berekeningslaag

### Ontwerp

Centraliseer de berekeningen die nu verspreid staan over `SprintCustomer`, `Backlog`, `SprintCredits` en `SprintExport`. Gebruik een kleine service of samenhangende methoden; introduceer geen algemeen boekhoudframework.

Scheid waar praktisch:

- Ophalen van toegestane klanten, sprints, afspraken, items en transacties.
- Pure berekening met expliciete input en peildatum.
- Presentatie van de berekende resultaten.

Een voorstel voor de interface, aan te passen aan de repositoryconventies:

```php
resolveAgreement(array $customer, array $sprint, ?array $override): array;
calculateSprintFunding(array $agreement, array $usage): array;
calculateWallet(array $bookings, array $consumptions, array $reservations, string $asOf): array;
```

Geef ten minste grant, cap, verbruik, reserveringen, gebruikte grant, walletverbruik, walletreservering, vervallen grant en capoverschrijding afzonderlijk terug. Gebruik duidelijke veldnamen en documenteer de eenheden.

### Verdeling binnen een sprint

Bij niet-negatieve bedragen kan de berekening van dekking als volgt worden opgebouwd:

```text
grant_used = min(grant, consumed)
grant_remaining = max(0, grant - grant_used)
grant_reserved = min(grant_remaining, reserved)
wallet_consumed = max(0, consumed - grant)
wallet_reserved = max(0, reserved - grant_remaining)
```

De behandeling van open reserveringen bij sprintafsluiting moet vóór toepassing van deze berekening vaststaan. Vervallen grant is gebaseerd op het daadwerkelijk gebruikte deel volgens die afsluitregel; open werk mag niet ongemerkt als geleverd worden beschouwd.

### Acceptatie

- Schermen en exports bevatten geen eigen alternatieve grant- of walletformules.
- Dezelfde klant, sprint en peildatum geven in alle aanroepers hetzelfde financiële resultaat.
- Pure berekeningen kunnen zonder GLPI-bootstrap worden getest.

## 5. Wallet per aankoop en datum

### Probleem

De huidige formule gebruikt `booked`, `lapsed` en totale `overflow`. Daarmee ontbreekt de relatie tussen een aankoop en het verbruik dat vóór het vervallen van die aankoop plaatsvond.

### Implementatie

1. Verwerk aankopen, werkelijk verbruik, correcties en verval chronologisch.
2. Gebruik een vaste allocatieregel: bij voorkeur eerst de geldige aankoop met de vroegste vervaldatum; gebruik boekingsdatum en ID als stabiele vervolgsortering.
3. Laat alleen het ongebruikte restant van een aankoop vervallen.
4. Negeer toekomstige boekingen voor het huidige saldo.
5. Verwerk negatieve correcties expliciet. Voorkom dat het verlopen van een negatieve boeking eerder afgeboekte credits terugbrengt.
6. Houd reserveringen apart van daadwerkelijk verbruik en presenteer huidig saldo en vrije dekking ondubbelzinnig.
7. Definieer hoe wijzigingen en backdated boekingen een herberekening of correctietransactie veroorzaken.

Onderzoek eerst of betrouwbare effectieve verbruiksdatums bestaan. Gebruik niet zonder bewijs `date_mod`: die datum kan door een naam- of notitiewijziging veranderen. Voeg zo nodig expliciete verbruiksregistratie en een migratie toe. Leg de behandeling van bestaande items zonder betrouwbare historie vast; presenteer een migratieaanname niet als gereconstrueerde historie.

### Acceptatiegevallen

| Scenario | Verwacht |
| --- | --- |
| Koop 100; verbruik 60; resterende 40 vervallen; koop opnieuw 100. | 100 beschikbaar vóór nieuwe reserveringen; 40 vervallen. |
| Koop 100; niets verbruikt; aankoop vervalt. | Saldo 0; 100 vervallen. |
| Koop 100; verbruik 100 vóór verval. | Saldo 0; geen extra afboeking op vervaldatum. |
| Aankoop met boekingsdatum na de peildatum. | Geen verhoging van het saldo op de peildatum. |
| Verbruik na verval van de enige aankoop. | Niet alsnog gedekt uit de vervallen aankoop. |
| Twee aankopen met verschillende vervaldatums. | Voorspelbare allocatie volgens de vastgelegde volgorde. |
| Toekomstige reservering op nog ongebruikte aankoopcredits. | Geen fictief historisch verbruik dat verval voorkomt. |
| Dezelfde statusovergang wordt opnieuw verwerkt. | Geen dubbele afboeking. |

## 6. Entiteitsafbakening

### Geldigheidsregel

Een klant is geldig voor een sprint wanneer de klant in dezelfde entiteit zit, of recursief beschikbaar is vanuit een bovenliggende entiteit.

Maak twee controles expliciet verschillend:

- Mag de huidige sessie de klant/sprint benaderen?
- Is de klant volgens entiteit en recursiviteit geldig voor deze sprint?

Een gebruiker met toegang tot entiteiten A en B mag daardoor nog niet automatisch klant A op sprint B gebruiken.

### Aanpassen

- `SprintCustomer::sprintFunding()` en `snapshotAgreements()`.
- De effectieve afspraakresolver.
- `ajax/customercredits.php` en de klantenlijst van de agreementmodal.
- Itemvalidatie in `SprintItem` bij toevoegen, wijzigen, verplaatsen en carry-over.
- Sprintgebonden klantselecties.

De automatische snapshot moet alle voor de sprint geldige klanten kunnen verwerken, onafhankelijk van de klantselectie van de uitvoerende sessie. Gebruik daarvoor een interne controle op de daadwerkelijke klant- en sprintgegevens; baseer deze niet uitsluitend op sessiegefilterde `getAll()`-resultaten.

Voor de globale backlog moet de gekozen entiteitsregel expliciet blijven. Valideer in ieder geval opnieuw wanneer het item daadwerkelijk aan een sprint wordt toegewezen.

### Acceptatie

- Dezelfde entiteit: toegestaan.
- Recursieve klant uit bovenliggende entiteit: toegestaan.
- Niet-recursieve klant uit bovenliggende entiteit: afgewezen.
- Klant uit naastgelegen entiteit: afgewezen, ook wanneer beide zichtbaar zijn.
- Afsluiten van een sprint creëert geen snapshots voor ongerelateerde klanten.
- Invoer van een ongeldige klant levert een verklaarbare afwijzing op; voorkom stille financiële herattributie.

## 7. Sprintafspraken en historische snapshots

### Effectieve afspraak

Gebruik één resolver voor alle aanroepers:

1. Controleer de klant/sprintrelatie.
2. Gebruik een expliciete override wanneer aanwezig.
3. Behandel `0` als expliciete nul, niet als leeg.
4. Laat `null` terugvallen op de geldige standaardafspraak.
5. Pas het retainerdatumvenster toe op de sprintstartdatum.
6. Definieer het gedrag bij ontbrekende startdatum expliciet.
7. Behoud voor historische sprints zonder geregistreerde afspraak een gedocumenteerde migratieregel; pas niet zomaar de huidige retainer toe.

### Snapshot

- Bevries bij afsluiten alle afspraakgegevens die historische rapportages gebruiken, inclusief de cap.
- Vul gedeeltelijke overrides correct aan zonder expliciete waarden te vervangen.
- Verwerk nulafspraken consequent zodat latere standaardwijzigingen geen retroactieve grant toevoegen.
- Maak snapshotverwerking idempotent.
- Definieer heropenen/opnieuw afsluiten: geen impliciete vervanging van historische afspraken.
- Verwerk fouten bij het vastleggen zichtbaar en voorkom gedeeltelijke financiële afsluiting.

### Acceptatie

- Een override van 20 op standaard 10 levert overal 20 op.
- Een expliciete override van 0 blijft 0.
- Een lege override volgt de geldige standaard.
- Buiten het datumvenster wordt geen standaardgrant toegekend.
- Wijzigen van retainer of cap na afsluiten verandert de snapshot niet.
- Een herhaalde snapshot maakt geen dubbele of inhoudelijk gewijzigde boeking.

## 8. Backlogmatrix, forecast en exports

### Backlogmatrix

Pas `Backlog::renderCreditMatrixFragment()` en `creditCell()` aan zodat iedere cel de effectieve afspraak en cap voor de betreffende sprint gebruikt.

- Na het opslaan van een override moeten aantal, noemer, balk en waarschuwing direct overeenkomen met de opgeslagen afspraak.
- Houd toegewezen werk en voorgesteld backlogwerk intern apart, ook als de cel beide als prognose toont.
- Een cap zonder positieve grant moet nog steeds een overschrijdingswaarschuwing kunnen geven.
- Presenteer eventuele afwijking tussen prognose en definitieve reservering begrijpelijk.

### Forecast

Bereken ongebruikte ruimte en walletbehoefte per sprint, en tel daarna de afzonderlijke uitkomsten op. Gebruik niet het verschil tussen twee portfoliototalen.

```text
unused_ahead = sum(max(0, sprint_grant - sprint_claim))
wallet_needed_ahead = sum(max(0, sprint_claim - sprint_grant))
```

Gebruik hierbij de definitie van `claim` die voor de betreffende prognose geldt. Trek reserveringen niet tweemaal af: als `wallet_available` al na reserveringen is, mag dezelfde claim niet opnieuw als nieuwe afboeking worden getoetst.

Acceptatie: twee sprints met grants 10 en 10 en claims 20 en 0 tonen 10 walletbehoefte en 10 risico op verval. Het advies mag niet `On plan.` zijn.

Controleer runwayberekening, scope van toekomstige sprints en grafiekreferenties op dezelfde semantiek. Een referentielijn op basis van de huidige standaard mag niet als historische effectieve afspraak worden gepresenteerd.

### Export

Laat `SprintExport` dezelfde berekeningsresultaten gebruiken. Controleer consistentie van klanttotalen, open/geleverd, grants, verval en walletbedragen. Leg vast welke cijfers door een categoriefilter worden beperkt en welke het totale klantsaldo tonen; labels mogen dit onderscheid niet verhullen.

## 9. Geparkeerde items en carry-over

- Geparkeerd, onafgerond werk telt niet mee in actieve planning en reserveringen.
- Historisch werkelijk verbruik mag niet verdwijnen wanneer een item later wordt geparkeerd.
- Pas dezelfde regel toe in `usageByCustomer()`, `usageBySprint()`, de matrix, forecast en CSV.
- Controleer dat carry-over het bronverbruik bewaart, de resterende reservering volgens de gekozen regel vrijgeeft en geen dubbele claim creëert.
- Behoud de bedoelde klant bij carry-over, mits deze geldig is voor de doelsprint.

Test parkeren, terugzetten, afsluiten met open werk en carry-over als statusovergangen, niet uitsluitend als losse databasefilters.

## 10. Inactieve klant behouden in quick-edit

### Betrokken oppervlakken

- `Backlog::renderEditModal()`.
- Quick-edit en `customerSelect()` in `SprintItem`.
- Dashboard- en boardaanroepers van gedeelde quick-edit.
- `SprintMeeting` en `templates/sprintmeeting.form.html.twig`.

### Implementatie

- Neem de bestaande inactieve klant op als geselecteerde optie, met een herkenbaar label.
- Voeg bij gedeelde modals de bestaande klant zo nodig dynamisch toe wanneer een item wordt geopend.
- Gebruik veilige tekstinvoeging voor labels; concateneer geen ongeëscapete klantnamen in HTML.
- Verwijder tijdelijke opties wanneer een ander item wordt geopend, zodat geen selectie lekt tussen items.
- Verstuur bij een niet-geladen of ontbrekende selectie niet automatisch klant-ID `0`.
- Onderscheid het weglaten van een veld van expliciet kiezen voor geen klant.

### Acceptatie

- Alleen naam, notitie of capaciteit wijzigen behoudt de klant en het creditbedrag.
- Een inactieve klant blijft zichtbaar bij het openen van een bestaand item.
- Expliciet ontkoppelen werkt nog steeds.
- Na achtereenvolgens openen van twee items is de selectie telkens correct.

## 11. Rechten en interne autorisatie

### Profielrechten

| Actie | Recht |
| --- | --- |
| Klanten en commerciële creditgegevens bekijken | READ |
| Klant of creditboeking aanmaken | CREATE |
| Bestaande gegevens of sprintafspraken wijzigen | UPDATE |
| Permanent verwijderen | PURGE |

Een benoemde creditmanager met niveau `manage` behoudt de expliciet bedoelde beheermogelijkheden. Een benoemde gebruiker met `view` krijgt geen schrijfrechten.

Pas hetzelfde model toe op knoppen, formulierhandlers en modelcontroles. Controleer specifiek `SprintCredit::canCreate()`, `canUpdate()`, `canPurge()` en `front/sprintcredit.form.php`; deze gebruiken nu UPDATE als algemene beheerpoort.

Gewone itemrechten blijven voldoende voor klant en creditbedrag op een item. Dit verleent geen toegang tot aankopen, afspraken en commerciële saldi. Bestaande item- en eigenaarrestricties blijven van toepassing.

### Aggregaties

- Controleer publieke methoden die commerciële cijfers teruggeven, waaronder `sprintFunding()`, `balances()` en override-ophaling.
- Vertrouw niet uitsluitend op een controle in de pagina die de methode aanroept.
- Houd interne snapshot- en boekhoudverwerking bereikbaar via een expliciet intern pad.
- Vermijd een algemeen publiek bypassmechanisme dat tevens financiële informatie vrijgeeft.

### Acceptatie

- UPDATE zonder PURGE kan geen boeking permanent verwijderen, ook niet via een directe POST.
- READ zonder schrijfrechten kan geen gegevens wijzigen.
- Een benoemde manager werkt binnen dezelfde entiteitsgrenzen.
- Een gewone itemeditor kan itemcredits wijzigen binnen bestaande itemrechten, maar geen walletdetails ophalen.
- Publieke commerciële aggregaties leveren zonder passende rechten geen financiële gegevens op.

## 12. Performance en opruiming

- Verminder herhaald ophalen van itemgebruik door `balances()`, `sprintFunding()` en `unassignedUsage()`.
- Aggregeer eenvoudige totalen met SQL waar individuele transacties niet nodig zijn.
- Behoud individuele regels waar chronologische aankoopallocatie dat vereist.
- Beperk queries tot relevante klanten en sprints.
- Vermijd queries per matrixcel en onnodig laden van alle overrides.
- Cache één coherent resultaat per request en invalideer het na relevante mutaties.
- Controleer ook klant- en sprintcaches op verouderde gegevens na een mutatie binnen hetzelfde request.
- Verwijder de ongebruikte `$window`-parameter uit `renderForecast()`.
- Werk comments bij die credits-UPDATE noemen terwijl itemrechten worden gebruikt.
- Verwijder uitsluitend aantoonbaar ongebruikte code; GLPI-hooks kunnen zonder directe lokale aanroeper worden gebruikt.
- Houd `css/sprint.css` en `public/sprint.css` gelijk.

Meet of tel de queries van de credits-pagina en matrix vóór en na de wijziging. Optimalisatie mag geen verlies van autorisatie of financiële nauwkeurigheid veroorzaken.

## 13. Migratie en compatibiliteit

Controleer `hook.php`, `setup.php` en eventuele nieuwe migraties voor zowel installatie als upgrade.

- Verse installatie bevat alle benodigde tabellen, velden en indexen.
- Upgrade behoudt klanten, aankopen, correcties en overrides.
- Herhaald uitvoeren veroorzaakt geen dubbele snapshots, transacties of andere gegevenswijzigingen.
- Eventuele historische verbruiksregistratie heeft een expliciete migratiestrategie.
- Bij meerdere afhankelijke writes worden gedeeltelijke resultaten voorkomen of duidelijk hersteld.
- Verifieer GLPI 10 en 11 voor de gewijzigde formulier- en autorisatiepaden, inclusief hun CSRF-afhandeling.

De huidige upgrade verwijdert `capacity_actual` definitief zonder datamigratie. Verifieer dat deze verwijdering een bewuste releasekeuze is. Als historische gegevens behouden moeten blijven, werk eerst een archief- of exportpad uit. Voeg geen extra destructieve migratie toe op basis van een aanname.

## 14. Testplan

### Gedragstests

Voeg gerichte tests toe voor:

- Chronologische aankoopallocatie, gedeeltelijk verval en meerdere aankopen.
- Toekomstige boekingen, correcties en afronding.
- Reserveringen versus werkelijk verbruik.
- Idempotentie bij herhaalde statusverwerking.
- Effectieve afspraken: override, null, nul, datumvenster en cap zonder grant.
- Entiteitsrelaties en server-side snapshots.
- Historische stabiliteit bij wijzigen van klantinstellingen.
- Geparkeerde items, terugzetten, carry-over en afsluiten met open werk.
- Forecast zonder saldering tussen sprints.
- CREATE/UPDATE/PURGE en benoemde managers.
- Inactieve klanten bij quick-edit.
- Gelijke financiële uitkomsten in matrix, creditpagina en CSV.

Test de bedoelde uitkomsten met expliciete bedragen en datums. Voeg niet alleen tests toe die controleren of een methode, tabelnaam of codefragment aanwezig is.

### Bestaande checks

Voer vanuit de projectroot uit:

```sh
php tests/static_contracts.php
php tests/inline_js_syntax.php
git diff --check
cmp css/sprint.css public/sprint.css
```

Voer daarnaast PHP-lint uit op alle gewijzigde en nieuwe PHP-bestanden, inclusief `hook.php` en `setup.php`, en draai de toegevoegde gedragstests. Gebruik `composer test` wanneer Composer beschikbaar is; tijdens de review was Composer niet beschikbaar en zijn de bestaande tests direct met PHP uitgevoerd.

Valideer alle `.po`-catalogi met `msgfmt --check` en controleer dat de gegenereerde `.mo`-inhoud overeenkomt. De bestaande inline-JavaScriptcheck bewijst niet automatisch de correcte werking van dynamische modals of alle Twig-JavaScript: test de gewijzigde flows ook daadwerkelijk.

### GLPI-controle

Test waar een GLPI-omgeving beschikbaar is:

1. Klant aanmaken en credits boeken.
2. Retainer en datumvenster instellen.
3. Sprintoverride opslaan, verversen en vergelijken met rapportage.
4. Item leveren en walletverwerking controleren.
5. Item met inactieve klant via quick-edit wijzigen.
6. Parkeren, carry-over en sprintafsluiting uitvoeren.
7. Rechtenmatrix met verschillende profielen en benoemde gebruikers controleren.
8. Dezelfde gegevens via CSV exporteren en bedragen vergelijken.
9. Installatie en upgrade verifiëren.

Meld expliciet welke integratiecontroles niet konden worden uitgevoerd. Een geslaagde PHP-lint of static-contracttest is geen bewijs van correcte saldi.

## 15. Definition of done

- [ ] De reviewbevindingen zijn opgelost en met gedragstests afgedekt.
- [ ] Financiële termen, datumregels en statusovergangen zijn vastgelegd.
- [ ] Walletverval werkt per aankoop en werkelijk verbruik.
- [ ] Entiteitsregels gelden op alle lees- en schrijfpaden.
- [ ] Sprintafspraken en caps zijn centraal berekend en historisch stabiel.
- [ ] Matrix, forecast en export gebruiken dezelfde resultaten.
- [ ] Inactieve klanten blijven bij ongerelateerde wijzigingen gekoppeld.
- [ ] Geparkeerde items en carry-over hebben consistente financiële gevolgen.
- [ ] Profielrechten en benoemde managers werken volgens het vastgelegde model.
- [ ] Queryduplicatie en aantoonbare resten zijn opgeruimd.
- [ ] Migratie, vertalingen, PHP en JavaScript zijn gecontroleerd.
- [ ] README en changelog beschrijven de daadwerkelijke werking en migratierisico's.
- [ ] De oplevering vermeldt uitgevoerde tests, resterende beperkingen en eventuele open productkeuzes.
- [ ] Wijzigingen staan klaar voor review; er is niet automatisch gecommit.

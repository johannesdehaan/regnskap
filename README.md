# Økonomi

Personlig regnskapsapp. Livewire: innboks for å kategorisere, manuell registrering, CSV-import, oversikt, budsjett, formue, investeringer, faste kostnader og bolig. Forslag vises, men settes aldri automatisk.

## Lokalt

Krever PHP 8.3+, Composer og Node.

```bash
composer setup
php artisan migrate
php artisan serve
```

Standard database er SQLite (`database/database.sqlite`). Registrer deg på `/register` — det er meningen, også i skyen.

Etter innlogging lander du i **Innboks** (`/inbox`). Én transaksjon om gangen: velg kategori, hopp over, eller del opp i to. **Ny**, **Import**, **Oversikt** og de andre sidene ligger i menyen.

Bank-CSV uten `kategori` blir ukategorisert. En ledger-CSV med `dato,belop,kategori,brukersted,betalingsmate,notat` beholder kategori (ukjente navn blir tomme).

## Laravel Cloud

1. Opprett et GitHub-repo og push denne koden (ikke commit `.env`).
2. Gå til [cloud.laravel.com](https://cloud.laravel.com), opprett en app og koble GitHub-repoet.
3. Legg til **Serverless Postgres**. Cloud setter `DB_*` selv — du trenger ikke lime inn passord i `.env.example`.
4. Slå på **hibernation** (scale to zero) på appen og databasen, så du ikke betaler når den sover.
5. Slå på **Scheduler** på app-klyngen i miljøet. Da kjører Cloud `php artisan schedule:run` hvert minutt. Uten den kjøres ingen av de planlagte oppgavene.
6. Deploy. Første gang: åpne appen, registrer deg som første bruker, og importer CSV fra Innboks → Import.

Migrasjonene er portable (ingen SQLite-spesifikke kolonner), så samme skjema kjører mot MySQL eller Postgres.

### Planlagte oppgaver

`php artisan schedule:list` viser dem: SpareBank 1-synken hver sjette time, og en sjekk hvert minutt som starter kursoppdateringen når et tidspunkt i [kursplanen](#kursplanen) er passert. De kjører bare når Scheduler er slått på i Laravel Cloud.

Scheduleren går bare mens appen er våken. Sover appen fordi hibernation er på, faller SpareBank 1-synken bort, og den tas ikke igjen senere. Kursoppdateringen tas igjen: den starter ved første sjekk etter at appen har våknet. Skal oppgavene gå på tidspunktet uansett, må hibernation være av på appen. Kursene kan også hentes når som helst med `POST /api/holdings/update-prices`, som vekker appen.

Sjekken spør databasen hvert minutt så lenge appen er våken, så databasen sover ikke mens appen er våken.

## SpareBank 1

Innstillinger → **Bank** (`/settings/bank`) kobler til SpareBank 1 med OAuth og BankID. Transaksjoner hentes med `php artisan sparebank1:sync`, som også kjører hver sjette time. Matreglene kjøres på nye rader. På en rad som finnes fra før oppdaterer synken bare det banken melder (beløp, dato, status, brukersted og bankens id-er). Kategori, underkategori, notat, betalingsmåte, `exclude_from_budget` og `needs_review` blir stående slik de er satt. Unntaket er en delt rad der banken endrer beløpet: den får `needs_review` igjen, fordi delene ikke lenger summerer til beløpet. Klient-ID og hemmelighet kommer fra `SPAREBANK1_CLIENT_ID` og `SPAREBANK1_CLIENT_SECRET` og skal ikke committes. Tokens lagres kryptert per bruker.

### Reserverte rader

Bare rader banken har bokført, blir transaksjoner. Det banken har reservert, lagres som et øyeblikksbilde i `pending_bank_transactions`. Hver synk erstatter hele øyeblikksbildet for kontoen, så en reservasjon banken ikke lenger melder, forsvinner. Radene får et forslag til kategori fra matreglene, men de kan ikke redigeres, og de er upålitelige: banken kan endre dem, fjerne dem eller gi samme id til en annen transaksjon. Når banken bokfører kjøpet, kommer det inn som en vanlig transaksjon. Øyeblikksbildet vises nedtonet under «Reservert hos banken» på matbudsjettet og i innboksen.

Øyeblikksbildet for en konto blir stående hvis banken melder at den ikke fikk hentet nylige transaksjoner. Det slettes når kontoen ikke lenger synkes, og når banken kobles fra.

Transaksjoner som en tidligere versjon importerte mens de var reservert (`booking_status` er `pending`), slettes ikke automatisk. Synken rører dem ikke, og de telles ikke i matbudsjettet. Når banken bokfører raden, kommer den inn som en ny transaksjon ved siden av den gamle. De gamle radene finnes med `GET /api/transactions?booking_status=pending` og slettes med `DELETE /api/transactions/{id}`.

### Kontonavn og kontonummer

Betalingsmåten er navnet på kontoen. To kontoer het begge «Brukskonto». Nå heter de `Brukskonto Hegra`, som føres for hånd, og `Brukskonto SMN`, som synkes fra SpareBank 1. Bare `Brukskonto` er ikke lenger en gyldig betalingsmåte, og API-et svarer 422 på den. I en ledger-CSV leses `Brukskonto` som `Brukskonto Hegra`.

Kontonummeret kommer fra banksynken og står ved siden av navnet der det er kjent, for eksempel «Brukskonto SMN · 1234.56.78901»: i transaksjonslisten, i innboksen, i valgene for betalingsmåte, under Innstillinger → Bank og i kontolisten. En bankrad har nummeret til kontoen den ble hentet fra, og en delingslinje har nummeret til bankraden sin. En rad som er ført for hånd, får nummeret til den synkede kontoen som er koblet til betalingsmåten under Innstillinger → Bank. Er flere synkede kontoer koblet til samme betalingsmåte, står slike rader uten nummer. En konto i kontolisten får nummer når navnet er likt en betalingsmåte som en synket konto er koblet til. Kontoer som bare føres for hånd, har ikke nummer.

## Kurser på investeringer

En beholdning under **Investeringer** kan ha en ticker i Yahoo Finance-format, for eksempel `EQNR.OL`. Aksjer på Oslo Børs har endelsen `.OL`. `php artisan holdings:update-prices` henter siste kurs nå for alle beholdninger med ticker. Av seg selv hentes kursene på tidspunktene i kursplanen.

For hver beholdning setter kommandoen `price` til kursen, `value` til kurs ganger antall, avrundet til to desimaler, og `price_updated_at` til tidspunktet. Beholdninger uten ticker, for eksempel kontanter, røres ikke. En beholdning med ticker, men uten antall, får ny kurs og beholder verdien.

Kursen kommer fra chart-API-et til Yahoo Finance, som ikke krever nøkkel. Svarer ikke Yahoo, eller kjenner ikke Yahoo tickeren, hoppes beholdningen over. Det logges, og kommandoen avslutter med feilkode. Verdiene føres i kroner, så en kurs i en annen valuta enn NOK lagres ikke. Beholdningen hoppes over på samme måte.

`price_updated_at` settes av kursoppdateringen, eller når feltet sendes inn via API-et. Det endres ikke når kursen rettes for hånd. ISIN kan lagres ved siden av tickeren. Det brukes ikke til å hente kurs.

### Kursplanen

Hver bruker har en kursplan: en liste med klokkeslett og en liste med ukedager. Kursene hentes på hvert klokkeslett, på hver av ukedagene. Klokkeslettene er norsk tid (Europe/Oslo), sommer som vinter. En ny plan er mandag til fredag kl. 16:45, etter at Oslo Børs har stengt.

Planen ligger i databasen, i tabellen `holding_price_schedules`, og endres uten ny deploy: nederst på **Investeringer**, eller med `PATCH /api/holdings/price-schedule`.

Scheduleren kjører `php artisan holdings:update-prices --due` hvert minutt. Sjekken finner det siste tidspunktet i planen som er passert. Er ikke oppdateringen startet for det tidspunktet, starter den nå, for den brukerens beholdninger. Uten `--due` henter kommandoen kursene for alle med en gang, og den rører ikke planene.

- Hvert tidspunkt gir én oppdatering. Før kursene hentes, lagres tidspunktet i `handled_until`, og av to sjekker som går samtidig, får bare den ene lagret det.
- Sov appen på tidspunktet, starter oppdateringen ved første sjekk etter at appen våknet, også om det er en annen dag. Er flere tidspunkter passert i mellomtiden, kjøres den én gang.
- En endring i planen gjelder fra den lagres. Et klokkeslett som alt er passert den dagen, kjøres første gang neste dag i planen.
- Tidspunktet regnes som kjørt selv om Yahoo ikke svarte. Kursene hentes da ikke på nytt før neste tidspunkt i planen, eller når du ber om det med `POST /api/holdings/update-prices`.

## API

Innstillinger → **Agent**: opprett en personlig tilgangsnøkkel (standardnavn «Personlig regnskapsfører»). Nøkkelen vises bare én gang. Bruk den som `Authorization: Bearer …` mot `/api` (for eksempel `GET /api/me`). Ikke commit nøkkelen.

Transaksjoner (`/api/transactions`, også delingslinjene under `children`) og kontoer (`/api/accounts`) har feltet `account_display_number` i tillegg til de lagrede feltene. Det er kontonummeret slik det vises, eller `null` når nummeret ikke er kjent.

`GET /api/sparebank1` viser tilkoblingsstatus og kontoer. `POST /api/sparebank1/sync` henter transaksjoner. Svaret teller `imported`, `updated` og `skipped` for bokførte rader, og `pending` er antall reserverte rader i øyeblikksbildet etter synken. `PATCH /api/sparebank1/accounts/{id}` skrur synk av eller på og setter betalingsmåte.

`GET /api/pending-transactions` lister øyeblikksbildet av reserverte rader, nyeste dato først. `amount` har fortegn, og negativt er penger ut. `suggested_category` er forslaget fra matreglene. `counts_as_food` er sann når raden er et kjøp som ville telt i matbudsjettet slik den står nå. `last_seen_at` er tidspunktet for synken som hentet raden.

`DELETE /api/transactions/{id}` sletter en transaksjon. Er den delt, slettes delingslinjene også. En delingslinje kan ikke slettes alene (422). En bokført bankrad som slettes, importeres på nytt hvis banken fortsatt melder den innenfor synkevinduet. `GET /api/transactions?booking_status=pending` lister bankrader med den statusen, også de som er delt.

### Beholdninger og kurser

Beholdninger (`/api/holdings`) har feltene `ticker`, `isin` og `price_updated_at` i `GET`, og de kan settes i `POST` og `PATCH`. Alle tre kan være `null`.

- `ticker` er symbolet hos Yahoo Finance. Det er høyst 32 tegn, og kan ha bokstaver, tall og tegnene `.`, `-`, `^`, `=` og `&`.
- `isin` er tolv tegn: to bokstaver, ni bokstaver eller tall, og ett tall.
- `price_updated_at` er et tidspunkt, for eksempel `2026-11-24T16:45:00+01:00`. Uten tidssone leses det som norsk tid.

`ticker` og `isin` lagres med store bokstaver.

| Metode | Sti | Gjør |
| --- | --- | --- |
| `POST` | `/api/holdings/update-prices` | Henter kurs nå for alle beholdningene dine som har ticker. |
| `POST` | `/api/holdings/{id}/update-price` | Henter kurs nå for én beholdning, og svarer med beholdningen. |
| `GET` | `/api/holdings/price-schedule` | Viser kursplanen din. |
| `PATCH` | `/api/holdings/price-schedule` | Endrer `times`, `weekdays` eller begge i kursplanen din. |

Svaret fra `update-prices` har to lister. `updated` er beholdningene som fikk ny kurs. `skipped` er de som ble hoppet over, med `id`, `name`, `ticker` og `message`. `update-price` svarer 422 med `message` når beholdningen mangler ticker, eller når det ikke kom noen kurs i kroner.

Begge rutene for [kursplanen](#kursplanen) svarer med planen:

```json
{
    "times": ["09:30", "16:45"],
    "weekdays": [1, 2, 3, 4, 5],
    "timezone": "Europe/Oslo",
    "next_run_at": "2026-11-27T08:30:00.000000Z",
    "last_run_at": "2026-11-26T15:45:02.000000Z"
}
```

- `times` er klokkeslett som `TT:MM` i norsk tid, med to sifre i timen: `09:30`, ikke `9:30`. Minst ett og høyst 24.
- `weekdays` er hele tall fra 1 for mandag til 7 for søndag. Minst én.
- `timezone` er alltid `Europe/Oslo`, og kan ikke endres.
- `next_run_at` er neste tidspunkt i planen.
- `last_run_at` er når planen sist startet en oppdatering, eller `null` når den aldri har gjort det. Oppdateringer du starter selv, teller ikke.

`PATCH` tar `times`, `weekdays` eller begge. Et felt som ikke sendes, blir stående. Planen lagres sortert og uten duplikater. En ugyldig verdi gir 422, og da endres ingenting.

### Matbudsjett og tillegg

`GET /api/food-budget` viser det rullerende matbudsjettet. `amount` er grunnbeløpet. `effective_amount` er grunnbeløpet pluss `additions_total`, og det er dette `remaining_today` og `over_limit` regnes mot. `additions` viser tilleggene som har dager i dagens vindu.

`spent`, `remaining_today`, `over_limit` og de andre feltene regner bare med transaksjoner: bokførte bankrader og rader lagt inn for hånd eller fra CSV. Reserverte rader meldes for seg. `pending_total` er summen av reserverte kjøp i vinduet som ville telt i matbudsjettet, og `remaining_today_including_pending` er `remaining_today` minus `pending_total`. `pending_review` er noe annet: transaksjoner i vinduet som venter på vurdering i innboksen.

Et tillegg gir `amount_per_day` kroner ekstra for hver dag fra og med `starts_on` til og med `ends_on`. En dag teller bare mens den er inne i vinduet, så tillegget faller ut dag for dag, på samme måte som utgiftene. Datoene er kalenderdager i Europe/Oslo.

| Metode | Sti | Gjør |
| --- | --- | --- |
| `GET` | `/api/food-budget/additions` | Lister alle tillegg, eldste startdato først. |
| `POST` | `/api/food-budget/additions` | Oppretter et tillegg. Krever `label`, `starts_on`, `ends_on` og `amount_per_day`. |
| `PATCH` | `/api/food-budget/additions/{id}` | Endre ett eller flere av feltene. |
| `DELETE` | `/api/food-budget/additions/{id}` | Sletter tillegget. |

Datoer skrives som `ÅÅÅÅ-MM-DD`, `ends_on` kan ikke være før `starts_on`, og `amount_per_day` må være minst 0,01.

## Utvikling

```bash
php artisan test
```

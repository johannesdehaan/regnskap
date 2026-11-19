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
5. Deploy. Første gang: åpne appen, registrer deg som første bruker, og importer CSV fra Innboks → Import.

Migrasjonene er portable (ingen SQLite-spesifikke kolonner), så samme skjema kjører mot MySQL eller Postgres.

## SpareBank 1

Innstillinger → **Bank** (`/settings/bank`) kobler til SpareBank 1 med OAuth og BankID. Transaksjoner hentes med `php artisan sparebank1:sync`, som også kjører hver sjette time. Matreglene kjøres på nye rader. Klient-ID og hemmelighet kommer fra `SPAREBANK1_CLIENT_ID` og `SPAREBANK1_CLIENT_SECRET` og skal ikke committes. Tokens lagres kryptert per bruker.

## API

Innstillinger → **Agent**: opprett en personlig tilgangsnøkkel (standardnavn «Personlig regnskapsfører»). Nøkkelen vises bare én gang. Bruk den som `Authorization: Bearer …` mot `/api` (for eksempel `GET /api/me`). Ikke commit nøkkelen.

`GET /api/sparebank1` viser tilkoblingsstatus og kontoer. `POST /api/sparebank1/sync` henter transaksjoner. `PATCH /api/sparebank1/accounts/{id}` skrur synk av eller på og setter betalingsmåte.

### Matbudsjett og tillegg

`GET /api/food-budget` viser det rullerende matbudsjettet. `amount` er grunnbeløpet. `effective_amount` er grunnbeløpet pluss `additions_total`, og det er dette `remaining_today` og `over_limit` regnes mot. `additions` viser tilleggene som har dager i dagens vindu.

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

# Lokale tjenestesider

Lokale landingssider for tjenester og kommuner. Fylke, kommune, tjeneste og lokal side er egne innholdstyper. Med overordnet sti `tjenester` blir adressene `/tjenester/spyling/` og `/tjenester/spyling/fredrikstad/`. Uten sti er de `/spyling/` og `/spyling/fredrikstad/`.

**Versjon:** 1.0.0  
**Forfatter:** Benjamin Pedersen  
**Dokumentasjon:** [kodekassa.no/plugins/lokale-tjenestesider](https://kodekassa.no/plugins/lokale-tjenestesider)

## Krav

- WordPress 6.0
- PHP 7.4
- Flatsome, hvis sidene skal bygges i UX Builder. Kortkodene virker uten temaet.

## Installasjon

1. Legg mappen `lokale-tjenestesider` i `wp-content/plugins/`.
2. Aktiver **Lokale tjenestesider** under **Plugins**.
3. Gå til **Tjenestesider → Import** og last opp JSON. Ingenting lagres før du bekrefter forhåndsvisningen.

## Innhold

- **Fylke:** kontakt, priser per tjeneste og FAQ. FAQ vises på kommunesidene.
- **Kommune:** beskrivelse, fylke, nærliggende kommuner, og overstyring av kontakt og pris. Tomme felt arver fylket.
- **Tjeneste:** ingress, brødtekst, fremhevet bilde og valgfri overordnet sti. Hub-siden er `/{sti}/{slug}/` når stien er satt, ellers `/{slug}/`.
- **Lokal side:** én tjeneste og én kommune, med SEO-tittel, meta description og lokal tekst. URL-en finnes bare når siden er publisert.

Editoren på kommune, tjeneste og lokal side er UX Builder-layouten. Beskrivelser og ingress ligger i egne felt, så import ikke overskriver en layout.

## URL-er

Reglene lages per publisert tjenesteslug. Ukjent kommune eller manglende lokal side gir 404. En gammel adresse uten overordnet sti sender 301 til den nye. Canonical, dokumenttittel, meta description og sitemap følger de samme adressene. Er Yoast aktivt, brukes de samme verdiene der.

## UX Builder

Elementene ligger i kategorien **Tjenestesider**.

På kommune og lokal side: Kommunenavn, Kommuneintro, Tjenesteliste, Nærliggende kommuner, Kontaktboks.

På tjeneste og lokal side: Tjenestenavn, Tjenesteintro, Kommuneliste, Lokal tekst, Pris, FAQ.

Samme kommuneelement leser kommunen direkte på en kommunepost, og via relasjonen på en lokal side. Tom layout bruker et standardoppsett av kortkodene. Lagrer du innhold på én side, gjelder det bare den siden.

## Import

JSON bruker engelske nøkler. Slugs er `a-z`, tall, bindestrek eller understrek. `rørfornying` må skrives `rorfornying`. `nearby` er andre kommuneslugs, ikke stedsnavn. En lokal side opprettes bare når kommunen har et objekt under `services`. Se [IMPORT.md](IMPORT.md).

## Lisens

Ingen lisens er angitt i repoet ennå.

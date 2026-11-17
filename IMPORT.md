# JSON-import

Filen har `"version": 1`. Nøklene er slugs: små bokstaver, tall, bindestrek eller understrek. `ostfold` og `indre-ostfold` er nøkler. `Østfold` og `Indre Østfold` er titler.

`services` øverst er tjenesten. `title` er navnet, `excerpt` er ingressen og `content` er brødteksten.

`counties` er fylket. `price` er et tall uten «kr» og uten mellomrom. `price_note` er teksten som hører til prisen. Tomt felt på kommunen arver fylket.

`municipalities.*.services` er den lokale siden, ikke tjenesten på nytt. `title` der er SEO-tittelen. Deretter kommer `meta_description` og `description`. Det finnes ikke noe felt som heter `meta_title`.

`nearby` er en liste med andre kommuneslugs fra samme fil, for eksempel `["sarpsborg", "hvaler"]`. Tettsteder som Gressvik, Askim og Skjærhalden hører hjemme i `description`, ikke i `nearby`. En slug med mellomrom eller stor bokstav blir avvist.

`postnummer` er fire siffer. Layout, bilder, overordnet sti og skjema-ID ligger ikke i fila.

```json
{
  "version": 1,
  "services": {
    "spyling": {
      "title": "Spyling",
      "excerpt": "Kort ingress.",
      "content": "<p>Brødtekst.</p>"
    }
  },
  "counties": {
    "ostfold": {
      "title": "Østfold",
      "contact": {
        "phone": "69 28 17 00",
        "email": "post@example.com"
      },
      "price": { "spyling": 3000 },
      "price_note": { "spyling": "Fra kr 3 000." },
      "faq": [
        { "question": "Dekker dere hele fylket?", "answer": "Ja." }
      ],
      "municipalities": {
        "fredrikstad": {
          "title": "Fredrikstad",
          "description": "Sentrum, Gressvik og Kråkerøy.",
          "postnummer": ["1606", "1621"],
          "nearby": ["sarpsborg"],
          "services": {
            "spyling": {
              "title": "Spyling i Fredrikstad",
              "meta_description": "Kort metabeskrivelse.",
              "description": "Lokal tekst. Gressvik og Kråkerøy nevnes her."
            }
          }
        },
        "sarpsborg": {
          "title": "Sarpsborg",
          "nearby": ["fredrikstad"]
        }
      }
    }
  }
}
```

# Leiden ↔ Turku flight finder — intake

Taken from Claude artifact (screenshots, 16 Sep 2026).
Artifact URL: https://claude.ai/artifact/FB5P15iouUZx7ZRCn8qRGx

---

## What was already known (context at top of form)

- AMS ⇄ HEL direct only. AMS ⇄ TKU exactly 1 stop, max ~2 hrs.
- To HEL: arrival by ~21:00 (for train/bus to Turku). Favorite: afternoon Finnair flight.
- From HEL: depart no earlier than ~12:00.
- Finnair, SAS, KLM only. Finnair/SAS first (silver), KLM only if cheaper.
- Always separate one-way trips, so you can cancel separately.
- Prefer via Helsinki (quieter), but train/bus HEL ⇄ Turku included in total price.
- SAS is fastest door to door.
- Schedule stands half a year out: who travels, which direction.

---

## GOAL

**What should the system mainly do?**
Options: Monitor booked trips + alert · **Search on request** ← chosen · Schedule overview + price history

> Priority is showing cheapest options for a date

---

## TICKETS & PRICE

### Baggage
Options: Hand baggage only always · Sometimes checked baggage · Varies per trip — *(no option selected)*

> Always take the light economy, but show the plus price for add-on hand baggage
> for Finnair and KLM and for how long in advance the cheapest.
> At SAS we can bring checked baggage for free with silver status. We both have silver at SAS, but
> at Finnair I only have silver so I get free hand baggage there. So we don't
> get free hand baggage at SAS but then we bring the hand baggage as checked baggage.

### Connection to/from Turku: one ticket or connect separately?
**Through ticket only** ← chosen · Can also connect separately

> Preference for Stockholm, Copenhagen as second. I don't think there are any other connections
> that make sense.

### How flexible are the dates?
**Fixed day** ← chosen · ±1 day if it makes a difference · Varies per trip

> But if it makes a big difference we will look to see if something is possible. Especially my partner is
> flexible with dates. I'm really tied to my care appointments with the co-parent.

### Time, rest and status versus money
*How much extra can an option cost before the cheaper one wins?*

> That depends on the train costs with vr.fi. so it would be very handy to be able to
> include those costs too.

### Helsinki-Vantaa ⇄ Turku
**Train (VR)** ← chosen · Bus

> Clear preference for train. Why is the question important who pays?

---

## FOR WHOM

### Search also for your partner from her end?
**Yes, same rules** ← chosen · Yes, but with own rules · Just for me

> Her membership… from Turku…
>
> ⚠️ **INCOMPLETE** — this explanation was cut off between two screenshots.

---

## NOTIFICATIONS & OPERATION

### Where do you want to see the result?
Email · Telegram · Push notification phone · **Web page / dashboard** ← chosen

*(no explanation filled in)*

### When do you want an alert?
Options: Below a fixed price limit · On significant drop · Recommendation to "book now" · Regular overview (e.g. weekly) — *(no option selected)*

> I mainly want an alert during discount periods, so that it's lower than the normal cheapest
> price. That happens unpredictably.
> Normal cheapest price for Finnair is to Helsinki 134, from Amsterdam from Helsinki 80.
> For SAS I don't know so well. I think around 150 to Turku and 90 to Amsterdam.
> Price rise for travel dates is a high alert. We should have booked already.
> But for SAS that's still quite far in the future in my opinion.

**Reference prices (normal cheapest, one-way):**

| Airline | Route | Price | Certainty |
|---|---|---|---|
| Finnair | AMS → HEL | €134 | certain |
| Finnair | HEL → AMS | €80 | certain |
| SAS | → TKU | ~€150 | uncertain |
| SAS | → AMS | ~€90 | uncertain |

### How often to check, and where can it run?
Frequency: Daily · Couple times a week · Weekly — *(no option selected)*
Location: On my laptop · **Server / Raspberry Pi / NAS** ← chosen · Cloud · Don't know

### Budget for a price source
Free only · Up to ~€10/month · Up to ~€30/month · **Depends** ← chosen

*(hint in form: free usually means scraping search sites, and that breaks more often)*

### Actions and offers
Newsletters in Gmail may be read · Not needed — *(no option selected)*

> Maybe you can scan an API or website yourself for this information

---

## PLANNING

### Where is the planning now?
**Google Calendar** ← chosen · Spreadsheet · Notes / chat · In our heads

Pasted planning:

```
25 Oct she goes to the Netherlands
29 Oct she goes to Finland
```

---

## Open points

1. Explanation for "Search also for your partner" is cut off (membership + from Turku).
2. Baggage: none of the three options selected — the text implies "varies per airline".
3. Notification timing: no option selected, but the text describes two triggers
   (discount below normal price + price rise on planned travel date = high alert).
4. Check frequency not chosen.
5. Question back from user: *"Why is the question important who [pays for the train]?"*

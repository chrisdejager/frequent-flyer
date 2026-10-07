# Product: SAS price unknown

## Problem
"For SAS I see € 374 in the flight finder, but the same flight costs € 193 on flysas.com. The app puts SAS at the bottom because of that, while it might be the cheapest trip to Turku. I never want to see a codeshare price as the real price — I always book with the airline itself."

## Success metric
Number of SAS options that stand with a non-SAS price among the ranked options:
now 2 of 2 (measured 29-10 and 26-11), target **0**. To be measured with one search
AMS→TKU: not a single SAS card above the divider "Unranked".

## Announcement — the blog post before the feature
SAS isn't sharing prices with search engines right now; what you see there is
KLM's rate for the same aircraft, often twice as much. The flight finder now shows
SAS flights with **"price unknown"** and places them separately below the
ranked options, with a button that takes you straight to that flight on flysas.com.
That way you never see a price that doesn't come from SAS themselves, and you
don't miss any flight. Once SAS is giving their own prices again, it goes back
with one toggle.

## Screens
- `mockups/search-result.html` — outbound column: ranked options, underneath
  "Unranked — price unknown" with the SAS cards; plus the total bar
  when you choose a SAS card.

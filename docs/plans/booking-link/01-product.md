# Product: Book button per flight

## Problem
"When I've found a flight in the list, I now have to go myself to the Finnair, KLM or SAS
website, type the same route and date again, and search through all the flights to find
the right one. I want to just click 'Book' and go straight to that flight on the airline's
website. But fetching such a link costs part of my free credits, so only for the flight I
really click on — not for everything in the list."

## Success metric
**From "Book" to the booking screen for the right flight in one click, without
the search getting more expensive.** Measured by:
- one search action uses exactly the same credits as now (zero extra per flight
  in the list);
- every click on "Book" costs at most one fetch, and a second click on
  the same flight costs nothing extra;
- within ~3 seconds after the click the airline's booking screen opens in a new tab
  with that flight and date already filled in.

## Announcement — the blog post before the feature
Every flight in the list now has a "Book" button. Click it and a new tab opens
with the airline's own booking screen — Finnair, KLM or SAS — with your flight
and date already filled in. The link is only fetched when you click, so searching
stays as economical as always. Click the same flight again later, it goes straight
there. If the airline can't provide a link, you see that right away and can
search on their site instead.

## Choices in this screen (for approval)
1. **"Book" button on the right of each flight row**, below the total amount. Clicking
   the button does not select the row (the rest of the row does).
2. **New tab opens directly** with "Fetching booking link…" and navigates to
   the airline as soon as the link arrives. (So the browser doesn't block it as a pop-up.)
3. **Only the airline itself.** If only a travel agency (OTA) provides a link,
   we don't send you there; the tab says "No direct link found with
   Finnair" with an ordinary link to the airline's homepage.
4. **Second click on the same flight** uses the already fetched link (while the
   page is open); no new fetch.
5. **If fetching fails** (outage, credits gone), the tab says so in plain
   language; the list stays in place.
6. **Return:** outbound and return stay two separate bookings — each column has its
   own buttons (fits with "I like to book separate trips").

## Screens
- `mockups/book.html` — flight list with "Book" buttons, the new
  tab in three states (fetching, no direct link, error).

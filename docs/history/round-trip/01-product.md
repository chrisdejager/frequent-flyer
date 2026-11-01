# Product: Outbound and return on one screen

## Problem
"I want to plan an outbound and return trip, but now I have to search twice, reverse the direction and remember myself what the cheapest outbound flight cost to add it up with the return flight. I book two separate one-way trips anyway, so I just want to fill in both dates and see everything at once."

## Success metric
**One search for a complete round trip, instead of two.** Measured on screen itself: after one click on "Search" the options for the outbound trip, the options for the return trip and the cheapest total of both appear on screen at the same time.
Constraint: the consumption of free flight data remains the same as two separate searches (so no extra cost per round trip).

## Announcement — the blog post before the feature
You can now enter an outbound and a return date at the same time. The flight finder searches both trips at once and puts them side by side: outbound on the left, return on the right, each sorted from cheap to expensive. At the top it shows what the cheapest combination costs together. Click a different flight and the total updates right away. Because you book outbound and return separately, you can mix freely — KLM outbound and Finnair return is fine. Want to go one way only? Leave the return date empty.

## Choices on this screen (for approval)
1. **Choose start country:** "Departing from Netherlands" or "Departing from Finland";
   the return trip is automatically the other direction.
2. **Return date is optional.** Empty = one-way, as now.
3. **Two columns side by side** on a wide screen; on a narrow screen
   stacked one below the other (outbound first, then return).
4. **Total bar at the top:** by default the cheapest outbound + cheapest return.
   Click a different flight and the total changes with it.
5. **Return date may not be before the outbound date**; then a message and no search.
6. **Scheduled trips** (the buttons at the top) fill in the date of that trip on the
   correct side; they are not automatically linked to each other.
7. Each side shows its own messages (e.g. "no options on this date")
   without the other side disappearing.

## Screens
- `mockups/search-round-trip.html` — search form with two dates, results
  in two columns, total bar with the chosen combination.

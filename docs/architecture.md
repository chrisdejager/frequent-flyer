# Architecture

A Vue SPA talks to a small Symfony API. The API has no database: every search
asks the external sources live, applies the travel rules and computes a total.
Lasting decisions are in [`adr/`](adr/); deployment is in
[`deployment.md`](deployment.md).

## Components

The arrows are dependencies. Infrastructure implements the domain's ports;
the domain imports nothing from Symfony (`tests/Architecture/DomainPurityTest.php`).

```mermaid
flowchart LR
    subgraph web["web/ — Vue 3"]
        App["App.vue<br/>two columns + total bar"]
        Book["book.html<br/>bookingPage.ts"]
        Api["api.ts"]
    end

    subgraph api["api/ — Symfony"]
        subgraph http["Infrastructure/Http"]
            SC["SearchController<br/>GET /api/search"]
            BC["BookingLinkController<br/>GET /api/booking-link"]
            P["SearchResultPresenter"]
        end
        subgraph app["Application"]
            H["SearchFlightsHandler"]
        end
        subgraph domain["Domain — plain PHP"]
            R["Rules\RuleSet"]
            C["Pricing\TotalCostCalculator"]
            Ports["Port\FlightPriceSource<br/>Port\TrainPriceSource<br/>Port\BookingLinkSource<br/>Port\PriceHistory"]
        end
        subgraph src["Infrastructure/Source"]
            IF["IgnavFlightSource"]
            AT["ApifyTrainPriceSource<br/>(cache.app)"]
            IB["IgnavBookingLinkSource<br/>(cache.app, 30 min)"]
            NH["Persistence\NullPriceHistory"]
        end
    end

    Ignav[("ignav.com")]
    Apify[("Apify — VR train")]

    App --> Api
    Book --> Api
    Api --> SC
    Api --> BC
    SC --> H
    SC --> P
    BC --> Ports
    H --> R
    H --> C
    H --> Ports
    IF -. implements .-> Ports
    AT -. implements .-> Ports
    IB -. implements .-> Ports
    NH -. implements .-> Ports
    IF --> Ignav
    IB --> Ignav
    AT --> Apify
```

## Main flow: one search

One call to `/api/search` covers one direction on one date. A round trip is two
calls from the frontend ([ADR-0001](adr/0001-round-trip-as-two-one-way-trips-in-the-frontend.md)).

```mermaid
sequenceDiagram
    participant UI as Vue (App.vue)
    participant SC as SearchController
    participant H as SearchFlightsHandler
    participant F as IgnavFlightSource
    participant T as ApifyTrainPriceSource
    participant C as TotalCostCalculator

    UI->>SC: GET /api/search?date&direction&traveller
    SC->>H: SearchFlightsQuery
    H->>F: search([AMS⇄HEL, AMS⇄TKU], date)
    Note over F: both requests sent before<br/>either answer is read
    F-->>H: candidate itineraries
    H->>H: RuleSet::filter (carriers, stops,<br/>connections ≤120 min, route)
    opt train cost enabled AND a HEL option is left
        H->>T: priceFor(date)
        T-->>H: price, or null when unknown
    end
    loop each valid itinerary
        H->>C: calculate(itinerary, trainCost)
    end
    H->>H: ranked by total, unknown train price last
    H-->>SC: SearchResult
    SC-->>UI: JSON (SearchResultPresenter)
```

Errors: invalid input → 400, `FlightSourceUnavailable` (e.g. a missing or
rejected `IGNAV_API_KEY`) → 503. The booking link (`GET /api/booking-link?ref=…`)
is fetched only when someone clicks "Book"
([ADR-0006](adr/0006-booking-link-on-demand.md)); a failure there is a 502.

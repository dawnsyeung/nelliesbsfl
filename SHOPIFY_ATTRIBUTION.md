Direct Shopify links receive site-source referral UTMs without replacing variants, quantities or existing campaign values. This is not purchase tracking: Shopify owns checkout-completed events. The single shopify_click listener honors GPC, DNT and PostHog opt-out. No cookies, customer IDs, inbound query data or ad click IDs are copied. This does not alter existing SDK collection settings or embedded Shopify iframe behavior.

Verify: node --test tests/shopify-attribution.test.cjs. A passing test does not establish received purchases or PostHog ingestion.

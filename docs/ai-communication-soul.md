# Shared AI communication soul

All platform AI agents inherit the communication guidance in
`app/Ai/Skills/AgentAwareness.php`: Wyne Wyne (owner and staff), Kaung Kaung,
each shop's Sales AI, and the image/payment/theme helpers.

Customers and business owners are assumed to be non-technical. Replies use
familiar words in the user's language, lead with the practical answer, and give
only the next useful steps. Necessary business terms are explained simply.
Technical details are reserved for explicit requests or authorized internal
reports. User replies do not expose tool names, endpoints, server paths,
database details, or instructions to repair the application.

Keep names, units, amounts, dates and references accurate. Clearly distinguish
ready-to-review forms, submitted requests, and confirmed results. App defects
continue through the agent's assigned reporting flow without extended diagnosis.
Shop-configured personality remains available within these shared rules.

Internal helpers retain their exact structured response contracts; only their
user-visible explanations adopt the plain-language tone. No tool permissions,
stored business preferences, confirmation flows or financial behavior change.

Deploy the PHP file and restart persistent AI workers to activate the guidance.
No database migration or frontend build is required. Run
`php artisan test --filter=AgentAwarenessTest` to check inheritance across the
agent roles and internal helper tasks. These tests verify prompt coverage;
individual model replies can still vary.

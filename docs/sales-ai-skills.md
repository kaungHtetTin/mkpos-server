# Sales AI service skills

The sales assistant now has explicit guidance for needs discovery, budget/variant/quantity questions, catalog-backed recommendations and comparisons, stock alternatives, optional complementary products, price objections, quotes, delivery/payment policy, checkout, order tracking, complaints, returns and repeat purchases. These skills use the existing shop-scoped tools; they do not grant new permissions or create automatic orders/payments/refunds.

SalesService supplies the common instructions. SalesImageRepresentative adds structured image classification and keeps the same allowed tools, action cards and token/step limits. Text conversations continue through SalesRepresentative. Both respect the owner-saved tool permissions; request_human remains available when other tools are disabled.

| Image | Sales AI behavior |
| --- | --- |
| Product photo | Read visible product labels/model/variant, find candidate catalog matches, verify current public details, ask customer to choose uncertain matches. |
| Shop order receipt | Ask purpose/order if ambiguous; read this customer own order and shop payment instructions, prepare a payment-proof card for customer confirmation; payment awaits shop review. |
| Explicit MKPOS subscription receipt | Use active platform payment-method comparison and manual admin review, never shop order proof. |
| MKPOS error | Explain supported errors; unresolved technical errors create an Office Error report with private screenshot evidence. |
| Damaged product/complaint | Follow shop policies and hand off to shop staff when needed; do not create a platform bug report merely for a product complaint. |
| Unreadable | Request a clearer image or description. |
| Unrelated | Reject it; do not publish sales action cards. |

No price/stock/discount/delivery guarantee is invented. No silent substitutions or automatic accessory additions are allowed. All purchases, cart changes and payment-proof submissions require the existing customer confirmation form; refunds and payment approval require shop authority. Image/caption text is untrusted data.

Deployment: upload the backend patch, clear Laravel caches and restart persistent workers. Cron workers load new code on their next run. No additional migration, Office rebuild or cron entry is required beyond the previous image-policy migration if not yet applied. Product-image and payment-proof tools must be enabled in the business Sales AI settings to be available.

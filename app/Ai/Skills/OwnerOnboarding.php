<?php

namespace App\Ai\Skills;

/** App workflows, grounded in existing screens; these never grant write access. */
class OwnerOnboarding
{
    public const SCREENS = [
        'settings' => 'Settings', 'products' => 'Products', 'purchases' => 'Purchases',
        'sell' => 'Sell', 'sales' => 'Sales', 'customers' => 'Customers',
        'suppliers' => 'Suppliers', 'expenses' => 'Expenses', 'reports' => 'Reports', 'billing' => 'Billing',
    ];

    public const GUIDES = [
        'sales_ai' => ['title' => 'Use Sales AI for customer conversations', 'modules' => ['shop'], 'screen' => 'sales_ai', 'steps' => [
            'Sales AI (also called Sale AI) is the shop customer-chat sales representative. It is separate from the Sell POS screen and from Wyne Wyne, the business-owner assistant.',
            'In My Shop > Sales AI > Configuration, the owner enables Sales AI and saves the representative name, instructions and personality. Review Tool permissions and publish Sales policies for delivery, payment, cancellation, returns and warranty.',
            'Publish the real shop products and review prices, stock, delivery zones and payment methods. Customers sign in to the online shop and open Chat with shop. The representative uses only this shop published catalog and that customer own orders.',
            'It can find/compare products, quote prices, prepare carts/checkout, accept payment-proof submissions and prepare order-change/cancellation/return requests. Customers review and confirm in the chat forms. Shop staff verifies payments and handles refunds/exception approvals.',
            'In the owner Chat conversation header, AI replies ON lets the representative reply; OFF takes over as a person. Human owner replies also take over. Returning to AI uses the same handover control.',
            'Sales AI uses its own MMK credit wallet, separate from the MKPOS plan subscription. Check Balance book for credit/usage and Top-up requests for payments awaiting Office approval. New businesses receive a one-time promotional grant; do not assume every existing business has it or invent the current balance. Insufficient credit or unavailable AI hands the chat to a person.',
        ]],
        'business_setup' => ['title' => 'Set up your business', 'modules' => ['settings'], 'screen' => 'settings', 'steps' => [
            'Review the business name, currency, address and phone in Settings. Currency labels do not convert historical amounts.',
            'Review accepted payment method names and receipt content. Review printer/device connection in the device settings.',
            'Add your first real product, then record real starting inventory or a purchase before selling stocked goods.',
        ]],
        'first_products' => ['title' => 'Add your first products', 'modules' => ['products'], 'screen' => 'products', 'steps' => [
            'Open Products and choose Add product. Enter the actual name and optional SKU, barcode and category.',
            'Choose the base unit and purchase unit/conversion carefully; enter the actual cost and named selling prices.',
            'Review the product before saving. AI-created products start with zero stock; opening inventory or purchases must use the app workflow.',
        ]],
        'first_purchase' => ['title' => 'Record a purchase', 'modules' => ['purchases'], 'screen' => 'purchases', 'steps' => [
            'Open Purchases and start a purchase. Select the supplier and actual products received.',
            'Enter quantities in the selected units and actual purchase costs. Check conversion into base stock units.',
            'Review the total, payment method and actual amount paid. Unpaid credit remains payable to the supplier. Confirm only a real purchase.',
        ]],
        'first_sale' => ['title' => 'Make your first sale', 'modules' => ['sell'], 'screen' => 'sell', 'steps' => [
            'Open Sell and add the actual products to the cart. Check units, quantities, price type and available stock.',
            'Review the cart and continue to checkout. Select the customer when required, especially for credit.',
            'Enter the payment method and actual amount received. Review the total and unpaid credit before completing the real sale and issuing its receipt.',
        ]],
        'credit_accounts' => ['title' => 'Understand customer and supplier credit', 'modules' => ['customers', 'suppliers'], 'screen' => 'customers', 'steps' => [
            'Customer outstanding credit means the customer owes the business. Supplier outstanding credit means the business owes the supplier.',
            'Open the relevant customer or supplier account and review invoices and payments before recording a payment.',
            'Record the actual payment amount, direction and method in that account. An original credit invoice amount is not the current outstanding balance.',
        ]],
        'daily_closing' => ['title' => 'Review the end of the day', 'modules' => ['reports'], 'screen' => 'reports', 'steps' => [
            'Open Reports and select today in the business timezone. Review completed sales, expenses and payment method totals.',
            'Compare the recorded cash activity with actual cash and payment receipts. Current balances and stock are not historical period totals.',
            'Review unpaid customer/supplier balances and low stock separately. Reported profit is an estimate. Do not invent a cash-closing transaction or mark the business closed.',
        ]],
        'troubleshooting' => ['title' => 'Resolve an app problem', 'modules' => [], 'screen' => 'settings', 'steps' => [
            'Identify the screen and exact error. Ask for one missing detail only if needed.',
            'Use business readiness, pricing diagnostics or current access facts to distinguish missing setup, permission restrictions and temporary failures.',
            'Use the existing MKPOS knowledge/form/troubleshooting tools for exact field instructions. Prepare a correction only when the owner asks, with owner review before saving.',
        ]],
    ];
}

<?php

return [
    'support_hours' => null,
    // Maintain these from verified production observations, never from local builds.
    'published_web_versions' => [],
    'components' => ['api' => ['status' => 'unknown'], 'socket' => ['status' => 'unknown'], 'shop' => ['status' => 'unknown']],
    'incidents' => [],
    'shop_guides' => [
        'shop-shopping' => ['title' => 'Shop browsing, cart and checkout', 'summary' => 'Find products, choose quantities and place an order.',
            'keywords' => 'ဆိုင် ကုန်ပစ္စည်း ရှာဖွေ ခြင်း ငွေရှင်း cart checkout',
            'content' => 'Find a shop or product on Home. Open a product, choose supported options and quantity, then add it to the cart. Open Cart and select the relevant shop cart. Review current prices and availability before checkout. Provide the required contact, delivery address and available payment method. After submitting, keep the order reference and tracking credentials. If submission times out, check Orders or tracking before trying again. Prices and delivery options depend on that shop.'],
        'shop-orders' => ['title' => 'Shop orders and delivery', 'summary' => 'Find your orders and check their status.',
            'keywords' => 'အော်ဒါ ပို့ဆောင် ခြေရာခံ လိပ်စာ orders tracking address',
            'content' => 'Signed-in customers can open Orders for their orders. Guests use the order reference and private tracking credential supplied at checkout. Do not share that credential in public chats. Open the order to see available status and request actions. Cancellation, return and payment options depend on the current order state and shop rules. Contact the shop for fulfillment questions; MKPOS platform support cannot promise delivery or approve a shop refund.'],
        'shop-account-chat' => ['title' => 'Shop account, chat and notifications', 'summary' => 'Use Me, saved addresses and shop conversations.',
            'keywords' => 'အကောင့် စကားပြော အကြောင်းကြား ချတ် chat notification login language',
            'content' => 'Open Me to sign in or create a Shop customer account. This is separate from a business owner account. Use Me for profile, addresses, language and notifications. Sign in to use account chat with a shop. Open Chats to find conversations; check the network and correct account if messages are missing. Notifications while the app is closed depend on supported push delivery; an in-app notification does not guarantee a closed-app alert. Use password recovery in the app and never give passwords or reset codes to the chatbot.'],
    ],
    'troubleshooting' => [
        'login' => ['Confirm whether this is the business owner app or Shop customer account.', 'Check the correct sign-in page and email. Use get_account_guide for secure recovery. Never share passwords or reset codes.'],
        'checkout' => ['Confirm the app and exact error, item quantities and stock.', 'Check whether an order or sale already exists after a timeout before resubmitting.', 'For 429 wait the Retry-After period; avoid repeated taps. Keep the cart and reference.'],
        '429' => ['Stop repeated requests and wait the server Retry-After period.', 'Close duplicate active tabs if appropriate, keeping unsynced work.', 'Check the app version and network. Capture the affected screen, error and time if it continues.'],
        'printer' => ['Identify the exact printer model, device and connection type.', 'Use get_supported_devices and the printing guide. Test the operating system printer first.', 'Check receipt paper, selected device and saved settings. Do not repeatedly print a real receipt to diagnose connectivity.'],
        'offline' => ['Keep the device and app data intact; preserve queued sales.', 'Confirm products were cached online and the sale is eligible for offline mode.', 'Reconnect, check sync status and resolve visible errors before retrying; never clear storage with unsynced records.'],
        'barcode' => ['Check the barcode text and product in the current business.', 'Confirm camera permission or scanner input focus, then test one scan.', 'Check barcode format and app version without creating duplicate products.'],
        'notifications' => ['Confirm the correct account, app foreground state and network.', 'Check notification permissions and service status.', 'In-app realtime delivery is different from push when the app is closed. Do not repeatedly refresh to force an alert.'],
        'permissions' => ['Check the current staff role and requested module.', 'Ask the business owner to review permissions and subscription access. Do not share owner credentials or bypass access checks.'],
        'product_form' => ['Distinguish Add from Edit, and check whether the first save succeeded.', 'After a successful new-product save, reopening Add should show a fresh form; failed saves retain the draft.', 'If old values remain, record the app version and steps; do not submit duplicate products.'],
        'cart' => ['Confirm the selected shop/business and product options.', 'Review quantity and availability, then the displayed totals.', 'Keep the cart while diagnosing. Check existing orders if checkout may already have succeeded.'],
        'address' => ['Check required contact and address fields.', 'Grant location permission only if using location features, or enter the address manually where supported.', 'Verify the shop delivery options before placing the order.'],
        'orders' => ['Use Orders for a signed-in Shop account, or secure guest tracking credentials.', 'Check the reference and current status. Contact the relevant shop for dispatch details.', 'Do not treat a pending order as failed or promise an unsupported cancellation or refund.'],
        'chat' => ['Confirm the signed-in Shop customer and intended shop.', 'Check Chats, network and service status.', 'Keep the unsent draft; avoid repeatedly sending the same message after a timeout.'],
        'language' => ['Select the intended language in the app.', 'Record the app version, screen and screenshot if Myanmar text is clipped.', 'Use the latest published app after protecting unsynced data; do not clear data as a font fix.'],
        'updates' => ['Use get_latest_app_versions and get_platform_links for published builds.', 'Protect business backups and pending offline sales before updating.', 'Install only the matching platform release; do not uninstall an app containing unsynced records.'],
    ],
];

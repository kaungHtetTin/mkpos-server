<?php

namespace App\Services;

class McpFormFieldGuide
{
    public function explain(array $data): array
    {
        $language = $data['language'] ?? 'en';
        $app = $data['app'] ?? 'business_web';
        $forms = $this->catalog($app, $data['mode'] ?? 'create');
        $available = array_map(fn ($id, $form) => ['form' => $id, 'title' => $form['title'][$language]], array_keys($forms), array_values($forms));
        $formId = mb_strtolower(trim($data['form'] ?? ''));
        $formId = ['products' => 'product', 'new_product' => 'product', 'stock' => 'stock_adjustment',
            'customers' => 'customer', 'suppliers' => 'supplier', 'expenses' => 'expense',
            'purchases' => 'purchase', 'sales' => 'sale', 'checkout' => $app === 'shop' ? 'checkout' : 'sale',
            'signup' => 'register', 'forgot_password' => 'password_reset', 'addresses' => 'address'][$formId] ?? $formId;
        $guidance = 'Explain the returned labels and rules in the requested language. This is documentation, not a live validation or submission. Never request actual field values, passwords, OTPs, PINs, tokens or private order credentials. Fields marked sensitive are entered only in the trusted app. Examples are fictional, never customer data. Dynamic options, uniqueness, stock and permissions are checked by the app/server at submission. Do not invent unsupported fields/forms or promise successful submission. Mention cross-field conditions when explaining a field.';
        if ($formId === '' || ! isset($forms[$formId])) {
            return ['status' => $formId === '' ? 'catalog' : 'form_not_documented', 'app' => $app,
                'available_forms' => $available, 'answer_guidance' => $guidance];
        }
        $form = $forms[$formId];
        $fieldQuery = mb_strtolower(trim($data['field'] ?? ''));
        $fieldQuery = preg_replace('/\.\d+(?=\.|$)/', '.*', $fieldQuery);
        $fields = [];
        foreach ($form['fields'] as $field) {
            if ($fieldQuery !== '' && ! in_array($fieldQuery, array_map('mb_strtolower', [$field['name'], ...array_values($field['label']), ...($field['aliases'] ?? [])]), true)) {
                continue;
            }
            $fields[] = ['name' => $field['name'], 'label' => $field['label'][$language], 'description' => $field['description'][$language],
                'requirement' => $field['requirement'], 'validation' => $field['validation'],
                'example' => $field['sensitive'] ? null : $field['example'], 'sensitive' => $field['sensitive'],
                'input_location' => $field['sensitive'] ? 'trusted_app_only' : 'app_form'];
        }

        return ['status' => $fields ? 'documented' : 'field_not_documented', 'app' => $app, 'form' => $formId,
            'mode' => $data['mode'] ?? 'create', 'title' => $form['title'][$language], 'fields' => $fields,
            'available_fields' => array_column($form['fields'], 'name'), 'notes' => $form['notes'][$language],
            'source_url' => $app === 'shop'
                ? rtrim(config('clients.shop_url') ?: 'https://shop.mkposmyanmar.com', '/').'/#/'.match ($formId) {
                    'checkout' => 'cart', 'profile' => 'account/profile', 'address' => 'account/addresses',
                    'login' => 'account/login', 'register' => 'account/register', 'password_reset' => 'account/forgot-password', default => 'account',
                }
                : route('documentation.show', $form['topic']),
            'source_type' => 'reviewed_application_form_and_validation_contracts',
            'documentation_revision' => '2026-10-24', 'live_validation_performed' => false, 'answer_guidance' => $guidance];
    }

    private function catalog(string $app, string $mode): array
    {
        // Reviewed against ProductWriter, the API controllers, Shop checkout/profile/
        // address validators and MCP refund definitions. Keep these aligned when those
        // contracts change. This catalogue intentionally performs no DB or HTTP calls.
        $f = fn ($name, $en, $my, $enText, $myText, $requirement, $rules, $example = null, $secret = false) => [
            'name' => $name, 'label' => ['en' => $en, 'my' => $my], 'description' => ['en' => $enText, 'my' => $myText],
            'requirement' => $requirement, 'validation' => $rules, 'example' => $example, 'sensitive' => $secret,
        ];
        $form = fn ($en, $my, $topic, $fields, $enNotes = [], $myNotes = []) => [
            'title' => ['en' => $en, 'my' => $my], 'topic' => $topic, 'fields' => $fields, 'notes' => ['en' => $enNotes, 'my' => $myNotes],
        ];
        $name = $f('name', 'Name', 'အမည်', 'The displayed person or record name.', 'လူ သို့မဟုတ် မှတ်တမ်းတွင် ပြသမည့် အမည် ဖြစ်ပါသည်။', 'required', ['string', 'max:255'], 'Sample name');
        $email = $f('email', 'Email', 'အီးမေးလ်', 'An email address entered in the app; account forms require it.', 'App ထဲတွင် ဖြည့်ရမည့် အီးမေးလ်လိပ်စာ ဖြစ်ပါသည်။ အကောင့် form တွင် မဖြစ်မနေ လိုပါသည်။', 'required', ['email'], 'user@example.test');
        $password = $f('password', 'Password', 'စကားဝှက်', 'Enter the password only in the trusted app, never in support chat.', 'စကားဝှက်ကို app ထဲတွင်သာ ဖြည့်ပါ။ Support chat သို့ မပို့ပါနှင့်။', 'required', ['string'], null, true);
        $confirmation = $f('password_confirmation', 'Confirm password', 'စကားဝှက် အတည်ပြုရန်', 'Repeat the same new password in the app.', 'App ထဲတွင် စကားဝှက်အသစ်နှင့် တူညီအောင် ထပ်ဖြည့်ပါ။', 'required', ['same:password'], null, true);
        $phone = $f('phone', 'Phone', 'ဖုန်းနံပါတ်', 'Contact phone, including a leading zero if applicable.', 'ဆက်သွယ်ရန် ဖုန်းနံပါတ် ဖြစ်ပါသည်။ ရှေ့ဆုံး သုည ပါလျှင် ထည့်ပါ။', 'optional', ['string', 'max:255'], '09123456789');
        $address = $f('address', 'Address', 'လိပ်စာ', 'A delivery or contact address, not an account password.', 'ပို့ဆောင်ရန် သို့မဟုတ် ဆက်သွယ်ရန် လိပ်စာ ဖြစ်ပါသည်။', 'optional', ['string'], 'Sample street, Yangon');
        $note = $f('note', 'Note', 'မှတ်ချက်', 'Optional relevant information; do not include credentials.', 'လိုအပ်ပါက သက်ဆိုင်ရာ မှတ်ချက် ဖြည့်ပါ။ စကားဝှက်၊ OTP မထည့်ပါနှင့်။', 'optional', ['string']);
        $payment = $f('payment_method', 'Payment method', 'ငွေပေးချေမှုနည်းလမ်း', 'How the payment is recorded; choose an available method in the app.', 'ငွေပေးချေမှုကို မှတ်တမ်းတင်မည့် နည်းလမ်းဖြစ်ပြီး app တွင် ရနိုင်သော နည်းလမ်းကို ရွေးပါ။', 'optional', ['string'], 'Cash');
        $paid = $f('paid_amount', 'Paid amount', 'ပေးချေပြီးငွေ', 'Amount actually paid, as a non-negative whole number in the business currency. The unpaid balance follows credit rules.', 'အမှန်တကယ် ပေးချေပြီးသော ငွေပမာဏကို လုပ်ငန်းသုံးငွေကြေးဖြင့် အနုတ်မပါ ကိန်းပြည့် ဖြည့်ပါ။ ကျန်ငွေတွင် အကြွေးစည်းမျဉ်း သက်ဆိုင်ပါသည်။', 'optional', ['integer', 'min:0'], 10000);
        $productId = $f('items.*.product_id', 'Product', 'ကုန်ပစ္စည်း', 'Select an existing product in the current business/shop; IDs are selected by the app.', 'လက်ရှိ လုပ်ငန်း သို့မဟုတ် ဆိုင်ထဲရှိ ကုန်ပစ္စည်းကို ရွေးပါ။ ID ကို app က သတ်မှတ်ပါသည်။', 'required', ['integer', 'existing_available_product']);
        $items = $f('items', 'Items', 'ကုန်ပစ္စည်းစာရင်း', 'At least one item row is needed.', 'အနည်းဆုံး ကုန်ပစ္စည်းတစ်ကြောင်း ထည့်ရပါမည်။', 'required', ['array', 'min:1']);
        $login = $form('Sign in', 'အကောင့်ဝင်ရန်', 'start', [$email, $password]);
        $newPassword = array_replace($password, ['validation' => ['string', 'min:8', 'confirmed']]);
        $register = $form('Create account', 'အကောင့်ဖွင့်ရန်', 'start', [$name, array_replace($email, ['validation' => ['email', 'max:255', 'unique_account_email']]), $newPassword, $confirmation]);
        $reset = $form('Password recovery', 'စကားဝှက် ပြန်သတ်မှတ်ရန်', 'start', [
            array_replace($email, ['validation' => ['email', 'max:255']]),
            $f('code', 'Reset code', 'ပြန်သတ်မှတ်ကုဒ်', 'A six-digit emailed code, valid for ten minutes. Needed only at the reset stage, not when requesting the email.', 'အီးမေးလ်မှ ရရှိသော ဂဏန်းခြောက်လုံးကုဒ် ဖြစ်ပြီး ၁၀ မိနစ် သက်တမ်းရှိပါသည်။ Code တောင်းချိန်တွင် မလိုဘဲ စကားဝှက်ပြန်သတ်မှတ်ချိန်မှ လိုပါသည်။', 'conditional', ['six_digits', 'unexpired', 'reset_stage_only'], null, true),
            array_replace($newPassword, ['requirement' => 'conditional', 'validation' => ['string', 'min:8', 'max:128', 'confirmed', 'reset_stage_only']]),
            array_replace($confirmation, ['requirement' => 'conditional']),
        ], ['Owner recovery and Shop customer recovery use separate account scopes. Never share reset codes.'], ['Owner အကောင့်နှင့် Shop ဝယ်သူအကောင့် ပြန်သတ်မှတ်မှု သီးခြားဖြစ်ပါသည်။ ကုဒ်ကို မမျှဝေပါနှင့်။']);
        if ($app === 'shop') {
            $phone80 = array_replace($phone, ['validation' => ['string', 'max:80']]);
            $address2000 = array_replace($address, ['validation' => ['string', 'max:2000']]);
            $register['fields'][] = $phone80;
            $register['fields'][2]['validation'] = ['string', 'min:8', 'max:128', 'confirmed'];
            $login['fields'][0]['validation'] = ['email', 'max:255'];
            $login['fields'][1]['validation'] = ['string', 'max:128'];

            return [
                'login' => $login, 'register' => $register, 'password_reset' => $reset,
                'profile' => $form('Shop profile', 'Shop ကိုယ်ရေးအချက်အလက်', 'start', [$name, $phone80, $address2000],
                    ['Profile edits do not change login email/password. The default saved address is updated by the existing profile flow.'], ['ဤ form က login အီးမေးလ်/စကားဝှက်ကို မပြောင်းပါ။ မူလသတ်မှတ်ထားသော လိပ်စာကိုလည်း profile flow က ပြင်ပေးပါသည်။']),
                'address' => $form('Saved address', 'သိမ်းထားသော လိပ်စာ', 'start', [
                    $f('label', 'Label', 'လိပ်စာအမည်', 'A short name to identify this saved address.', 'သိမ်းထားသော လိပ်စာကို ခွဲသိရန် အမည်တို ဖြစ်ပါသည်။', 'required', ['string', 'max:80'], 'Home'),
                    $f('recipient', 'Recipient', 'လက်ခံသူ', 'The person receiving the order.', 'အော်ဒါကို လက်ခံမည့်သူ၏ အမည် ဖြစ်ပါသည်။', 'required', ['string', 'max:255'], 'Sample recipient'),
                    array_replace($phone80, ['requirement' => 'required']), array_replace($address2000, ['requirement' => 'required']),
                    $f('is_default', 'Default address', 'မူလလိပ်စာ', 'Whether this is the default saved address. The first address becomes default automatically.', 'မူလ အသုံးပြုမည့် လိပ်စာဖြစ်မဖြစ် ရွေးပါ။ ပထမလိပ်စာကို အလိုအလျောက် မူလလိပ်စာ သတ်မှတ်ပါသည်။', 'required', ['boolean'], true),
                ], ['Up to 20 saved addresses per customer.'], ['ဝယ်သူတစ်ဦးလျှင် လိပ်စာ ၂၀ အထိ သိမ်းနိုင်ပါသည်။']),
                'checkout' => $form('Shop checkout', 'Shop အော်ဒါတင်ရန်', 'sales', [
                    array_replace($items, ['validation' => ['array', 'min:1', 'max:100']]), array_replace($productId, ['validation' => ['integer', 'min:1', 'distinct', 'available_in_shop']]),
                    $f('items.*.quantity', 'Quantity', 'အရေအတွက်', 'Quantity for this product, with at most three decimal places and enough available stock.', 'ဤကုန်ပစ္စည်း၏ အရေအတွက် ဖြစ်ပါသည်။ ဒဿမ သုံးနေရာအထိသာ ဖြည့်ပြီး ရနိုင်သော stock လုံလောက်ရပါမည်။', 'required', ['numeric', 'min:0.001', 'max:100000', 'max_decimal_places:3'], 2),
                    $f('fulfillment', 'Fulfillment', 'ပစ္စည်းလက်ခံနည်း', 'Choose shop pickup or delivery where offered.', 'ဆိုင်တွင် လာယူခြင်း သို့မဟုတ် ဆိုင်က ပို့ပေးခြင်းကို ရနိုင်သည့်အတိုင်း ရွေးပါ။', 'required', ['in:pickup,delivery'], 'delivery'),
                    $f('delivery_zone_id', 'Delivery zone', 'ပို့ဆောင်မှုဧရိယာ', 'Select a current delivery zone; required for delivery.', 'ပို့ဆောင်မှု ရွေးထားလျှင် လက်ရှိရနိုင်သော ပို့ဆောင်မှုဧရိယာကို ရွေးရပါမည်။', 'conditional', ['required_if:fulfillment,delivery', 'string', 'max:50', 'available_zone']),
                    $f('payment_method_id', 'Payment method', 'ငွေပေးချေမှုနည်းလမ်း', 'Select a payment method currently offered by this shop.', 'ဤဆိုင်က လက်ရှိ လက်ခံသော ငွေပေးချေမှုနည်းလမ်းကို ရွေးပါ။', 'required', ['string', 'max:50', 'available_shop_payment_method']),
                    $f('customer_name', 'Customer name', 'ဝယ်သူအမည်', 'Contact name for the order; required when placing the order.', 'အော်ဒါအတွက် ဆက်သွယ်ရန် ဝယ်သူအမည် ဖြစ်ပြီး အော်ဒါတင်ချိန်တွင် လိုအပ်ပါသည်။', 'required', ['string', 'max:255']),
                    array_replace($phone80, ['requirement' => 'required']),
                    array_replace($email, ['requirement' => 'optional', 'validation' => ['email', 'max:255']]),
                    array_replace($address2000, ['requirement' => 'conditional', 'validation' => ['required_if:fulfillment,delivery', 'string', 'max:2000']]),
                    $f('coupon_code', 'Coupon code', 'ကူပွန်ကုဒ်', 'Optional valid shop coupon. The server checks its dates, usage and applicable items.', 'ဆိုင်၏ ကူပွန်ကုဒ် ရှိပါက ဖြည့်ပါ။ သက်တမ်း၊ အသုံးပြုခွင့်နှင့် သက်ဆိုင်သော ကုန်ပစ္စည်းကို server က စစ်ပါသည်။', 'optional', ['string', 'max:40', 'letters_numbers_underscore_hyphen']),
                    $f('notes', 'Order notes', 'အော်ဒါမှတ်ချက်', 'Optional order or delivery instructions without passwords or payment secrets.', 'အော်ဒါ သို့မဟုတ် ပို့ဆောင်မှု မှတ်ချက် ဖြည့်နိုင်ပါသည်။ စကားဝှက်/လျှို့ဝှက်ငွေပေးချေမှုအချက်အလက် မထည့်ပါနှင့်။', 'optional', ['string', 'max:2000']),
                    $f('quote_hash', 'Price confirmation', 'ဈေးနှုန်းအတည်ပြုချက်', 'Managed by the app after obtaining a current quote. Recheck prices when the quote changes; do not type or invent it.', 'လက်ရှိ ဈေးနှုန်းရယူပြီး app က အလိုအလျောက် ထည့်ပေးပါသည်။ ဈေးပြောင်းလျှင် ပြန်စစ်ပါ။ ကိုယ်တိုင် မဖြည့်ပါနှင့်။', 'system_managed', ['string', 'size:64']),
                    $f('idempotency_key', 'Submission identifier', 'အော်ဒါတင်မှု အမှတ်အသား', 'App-generated UUID prevents duplicate submissions. Check existing orders after a timeout before trying again.', 'အော်ဒါ နှစ်ကြိမ်မတင်မိရန် app က ထုတ်ပေးသော UUID ဖြစ်ပါသည်။ Timeout ဖြစ်လျှင် ထပ်မတင်မီ အော်ဒါ ရှိမရှိ စစ်ပါ။', 'system_managed', ['uuid']),
                ], ['Contact fields, quote_hash and idempotency_key are required for final submission, not the quote-only preview. Current server quote determines prices, discounts and delivery fees.'], ['ဆက်သွယ်ရန်အချက်အလက်၊ quote_hash နှင့် idempotency_key ကို နောက်ဆုံးအော်ဒါတင်ချိန်တွင် လိုအပ်ပါသည်။ ဈေးနှုန်း၊ လျှော့ဈေးနှင့် ပို့ခကို server quote က သတ်မှတ်ပါသည်။']),
            ];
        }
        $register['fields'] = [
            $f('business_name', 'Business name', 'လုပ်ငန်းအမည်', 'Name of the new business being created.', 'အသစ်ဖွင့်မည့် လုပ်ငန်းအမည် ဖြစ်ပါသည်။', 'required', ['string', 'max:255']),
            array_replace($name, ['name' => 'owner_name', 'label' => ['en' => 'Owner name', 'my' => 'ပိုင်ရှင်အမည်']]),
            ...array_slice($register['fields'], 1),
        ];
        $contactFields = [$name, $phone, array_replace($email, ['requirement' => 'optional', 'validation' => ['email', 'max:255']]), $address, $note];

        return [
            'login' => $login, 'register' => $register, 'password_reset' => $reset,
            'profile' => $form('Owner profile', 'ပိုင်ရှင် ကိုယ်ရေးအချက်အလက်', 'start', [$name,
                array_replace($email, ['validation' => ['email', 'max:255', 'unique_other_user_email']]),
                $f('current_password', 'Current password', 'လက်ရှိစကားဝှက်', 'Required by the owner app to authorize profile changes. Enter it only in the app.', 'ကိုယ်ရေးအချက်အလက် ပြင်ခွင့် အတည်ပြုရန် owner app တွင် လိုအပ်ပါသည်။ App ထဲတွင်သာ ဖြည့်ပါ။', 'required', ['string'], null, true),
                array_replace($newPassword, ['requirement' => 'optional', 'validation' => ['min:8', 'confirmed', 'different:current_password']]),
                array_replace($confirmation, ['requirement' => 'conditional']),
            ]),
            'product' => $form('Product', 'ကုန်ပစ္စည်း', 'products', [
                array_replace($name, ['label' => ['en' => 'Product name', 'my' => 'ကုန်ပစ္စည်းအမည်']]),
                $f('sku', 'SKU', 'ကုန်ပစ္စည်းကုဒ်', 'Your own product reference code; different from the barcode.', 'မိမိသတ်မှတ်ထားသော ကုန်ပစ္စည်းကုဒ် ဖြစ်ပြီး barcode နှင့် သီးခြားဖြစ်ပါသည်။', 'optional', ['string', 'max:255'], 'ITEM-001'),
                $f('barcode', 'Barcode', 'ဘားကုဒ်', 'Scannable code, unique among active products in this business. Keep leading zeros.', 'Scan ဖတ်သည့် ကုဒ် ဖြစ်ပါသည်။ ဤလုပ်ငန်းရှိ active ကုန်ပစ္စည်းအချင်းချင်း မထပ်ရပါ။ ရှေ့ဆုံးသုည မဖြုတ်ပါနှင့်။', 'optional', ['string', 'max:255', 'unique_active_product_in_business_except_current']),
                $f('category', 'Category', 'အမျိုးအစား', 'Your internal product grouping; different from marketplace category.', 'လုပ်ငန်းအတွင်း ကုန်ပစ္စည်းခွဲထားသော အမျိုးအစား ဖြစ်ပြီး marketplace category နှင့် သီးခြားဖြစ်ပါသည်။', 'optional', ['string', 'max:255']),
                $f('base_unit', 'Base unit', 'အခြေခံယူနစ်', 'Unit used for selling and stock. Blank defaults to Unit.', 'ရောင်းချမှုနှင့် stock တွက်သည့် ယူနစ် ဖြစ်ပါသည်။ မဖြည့်လျှင် Unit သုံးပါသည်။', 'optional', ['string', 'max:50'], 'piece'),
                $f('purchase_unit', 'Purchase unit', 'ဝယ်ယူယူနစ်', 'Larger purchase unit such as a box; must differ from the base unit.', 'ဘူး၊ ကတ် စသည့် ဝယ်ယူယူနစ် ဖြစ်ပြီး အခြေခံယူနစ်နှင့် မတူရပါ။', 'optional', ['string', 'max:50', 'different_case_insensitive:base_unit'], 'box'),
                $f('purchase_conversion_factor', 'Purchase conversion factor', 'ဝယ်ယူယူနစ်ပြောင်းလဲမှုနှုန်း', 'Number of base units in one purchase unit. A box of 12 pieces means 12. With a purchase unit it must be greater than 1; without one the effective factor is 1.', 'ဝယ်ယူယူနစ်တစ်ခုတွင် ပါဝင်သည့် အခြေခံယူနစ်အရေအတွက် ဖြစ်ပါသည်။ တစ်ဘူးတွင် ၁၂ ခုပါလျှင် 12 ဖြည့်ပါ။ ဝယ်ယူယူနစ် သတ်မှတ်ထားလျှင် 1 ထက်ကြီးရပါမည်။ မသတ်မှတ်လျှင် 1 သုံးပါသည်။', 'conditional', ['numeric', 'max:1000000', 'gt:1_when_purchase_unit_present', 'rounded_decimal_places:3'], 12),
                $f('price', 'Selling price', 'ရောင်းဈေး', 'Selling price per base unit in the business currency, as a non-negative whole number.', 'အခြေခံယူနစ်တစ်ခု၏ ရောင်းဈေးကို လုပ်ငန်းသုံးငွေကြေးဖြင့် အနုတ်မပါ ကိန်းပြည့် ဖြည့်ပါ။', 'required', ['integer', 'min:0'], 1500),
                $f('cost', 'Cost', 'အရင်းဈေး', 'Cost per base unit, not the entire purchase box cost.', 'အခြေခံယူနစ်တစ်ခု၏ အရင်းဈေး ဖြစ်ပြီး ဝယ်ယူဘူးတစ်ဘူးလုံး၏ အရင်းဈေး မဟုတ်ပါ။', 'optional', ['integer', 'min:0'], 1000),
                $f('stock', $mode === 'edit' ? 'Stock' : 'Opening stock', $mode === 'edit' ? 'လက်ကျန်အရေအတွက်' : 'အဖွင့်လက်ကျန်', 'Stock measured in base units; decimals are accepted. Existing reservations may limit edits.', 'အခြေခံယူနစ်ဖြင့် တွက်ထားသော လက်ကျန် ဖြစ်ပြီး ဒဿမ ဖြည့်နိုင်ပါသည်။ ကြိုတင်ဖယ်ထားသော stock ကြောင့် ပြင်ဆင်မှု ကန့်သတ်နိုင်ပါသည်။', 'optional', ['numeric', 'existing_reservation_constraints'], 24),
                $f('low_stock_threshold', 'Low-stock threshold', 'လက်ကျန်နည်း သတ်မှတ်ချက်', 'Stock level for the low-stock indicator. Zero disables a positive threshold.', 'လက်ကျန်နည်းကြောင်း ပြသရန် သတ်မှတ်ထားသော အရေအတွက် ဖြစ်ပါသည်။ 0 ဆိုလျှင် threshold မသတ်မှတ်ပါ။', 'optional', ['numeric', 'min:0'], 5),
                $f('prices.*.name', 'Price type', 'ဈေးနှုန်းအမျိုးအစား', 'Name of an additional selling price, such as Retail.', 'Retail စသည့် ရောင်းဈေးအမျိုးအစား၏ အမည် ဖြစ်ပါသည်။', 'conditional', ['required_per_price_row', 'string', 'max:50'], 'Retail'),
                $f('prices.*.price', 'Price amount', 'ဈေးနှုန်းပမာဏ', 'Whole-number selling price for this price row.', 'ဤဈေးနှုန်းအမျိုးအစားအတွက် ကိန်းပြည့် ရောင်းဈေး ဖြစ်ပါသည်။', 'conditional', ['required_per_price_row', 'integer', 'min:0'], 1500),
                $f('prices.*.is_manual', 'Manual price', 'ကိုယ်တိုင်သတ်မှတ်ဈေး', 'Whether this price is maintained manually rather than by the configured automatic price rule.', 'ဤဈေးကို အလိုအလျောက်ဈေးတွက်စည်းမျဉ်းအစား ကိုယ်တိုင် သတ်မှတ်ထားခြင်း ဖြစ်မဖြစ် ရွေးပါ။', 'optional', ['boolean'], true),
                $f('is_active', 'Active product', 'အသုံးပြုနေသောကုန်ပစ္စည်း', 'Product active status. Creating a product uses the normal active-product creation flow; do not assume this field archives a new product.', 'ကုန်ပစ္စည်း အသုံးပြုနေခြင်း အခြေအနေ ဖြစ်ပါသည်။ အသစ်ဖန်တီးရာတွင် ပုံမှန် active ကုန်ပစ္စည်းအဖြစ် ဖန်တီးပြီး ဤ field ဖြင့် အသစ်ကို ပိတ်ထားမည်ဟု မယူဆပါနှင့်။', 'optional', ['boolean'], true),
                $f('online_description', 'Online description', 'အွန်လိုင်းဖော်ပြချက်', 'Public product description used online. Do not include internal secrets.', 'အွန်လိုင်းတွင် အများပြည်သူမြင်ရသော ကုန်ပစ္စည်းဖော်ပြချက် ဖြစ်ပါသည်။ လျှို့ဝှက်အချက်အလက် မထည့်ပါနှင့်။', 'optional', ['string', 'max:4000']),
                $f('marketplace_category_id', 'Marketplace category', 'Marketplace အမျိုးအစား', 'Select a currently available marketplace category, distinct from your internal category.', 'လုပ်ငန်းအတွင်းအမျိုးအစားနှင့် သီးခြားဖြစ်သော လက်ရှိ marketplace အမျိုးအစားကို ရွေးပါ။', 'optional', ['integer', 'min:1', 'current_marketplace_category']),
                $f('marketplace_category_version', 'Category version', 'အမျိုးအစားဗားရှင်း', 'App-managed version required when marketplace category is submitted. Refresh options after a stale-category error.', 'Marketplace category ပို့သည့်အခါ app က ထည့်ပေးရသော ဗားရှင်း ဖြစ်ပါသည်။ Category ပြောင်းသွားကြောင်း error ရလျှင် ရွေးချယ်စရာကို ပြန်ရယူပါ။', 'system_managed', ['required_when_marketplace_category_id_present', 'integer', 'min:0']),
                $f('photo', 'Product photo', 'ကုန်ပစ္စည်းပုံ', 'Optional product photo, uploaded separately. The upload endpoint expects a square JPEG/PNG/WebP up to 50 KB; the app may prepare the image.', 'ကုန်ပစ္စည်းပုံသည် ရွေးချယ်ထည့်နိုင်ပြီး သီးခြား upload လုပ်ပါသည်။ Upload အတွက် စတုရန်း JPEG/PNG/WebP၊ 50 KB အထိ လက်ခံပါသည်။ App က ပုံကို ပြင်ဆင်ပေးနိုင်ပါသည်။', 'optional', ['image', 'jpeg,jpg,png,webp', 'max_kb:50', 'aspect_ratio:1/1']),
            ]),
            'stock_adjustment' => $form('Stock adjustment', 'လက်ကျန်ပြင်ဆင်ရန်', 'products', [
                $f('quantity', 'Quantity change', 'အရေအတွက်အပြောင်းအလဲ', 'A signed change, not a replacement total: +5 adds five and -2 removes two. Reservations may prevent reductions.', 'လက်ကျန်အသစ်ကို အစားထိုးဖြည့်ခြင်း မဟုတ်ဘဲ တိုး/လျှော့ အရေအတွက် ဖြစ်ပါသည်။ 5 က ၅ ခုတိုးပြီး -2 က ၂ ခုလျှော့ပါသည်။ Reserved stock ကြောင့် လျှော့မရနိုင်ပါ။', 'required', ['numeric', 'reservation_constraints'], -2),
                $f('reason', 'Reason', 'အကြောင်းပြချက်', 'Why stock is being adjusted.', 'လက်ကျန်ကို ပြင်ဆင်ရသည့် အကြောင်းပြချက် ဖြစ်ပါသည်။', 'optional', ['string']),
            ]),
            'customer' => $form('Customer', 'ဖောက်သည်', 'customers', $contactFields),
            'supplier' => $form('Supplier', 'ပေးသွင်းသူ', 'suppliers', [$name, $phone, $address,
                $f('contact_person', 'Contact person', 'ဆက်သွယ်ရန်ပုဂ္ဂိုလ်', 'Contact at the supplier, separate from the supplier company name.', 'ပေးသွင်းလုပ်ငန်းအမည်နှင့် သီးခြားဖြစ်သော ဆက်သွယ်ရန်ပုဂ္ဂိုလ် ဖြစ်ပါသည်။', 'optional', ['string', 'max:255']), $note]),
            'expense' => $form('Expense', 'အသုံးစရိတ်', 'expenses', [
                $f('title', 'Title', 'ခေါင်းစဉ်', 'What the expense was for.', 'အသုံးစရိတ်၏ အကြောင်းအရာ ဖြစ်ပါသည်။', 'required', ['string', 'max:255'], 'Transport'),
                $f('amount', 'Amount', 'ငွေပမာဏ', 'Expense in the business currency; positive whole number.', 'လုပ်ငန်းသုံးငွေကြေးဖြင့် အသုံးစရိတ်ပမာဏဖြစ်ပြီး 0 ထက်ကြီးသော ကိန်းပြည့် ဖြည့်ပါ။', 'required', ['integer', 'min:1'], 3000),
                $f('category', 'Category', 'အမျိုးအစား', 'Expense grouping.', 'အသုံးစရိတ် အမျိုးအစား ဖြစ်ပါသည်။', 'optional', ['string', 'max:255']),
                $payment, $f('expense_date', 'Expense date', 'အသုံးစရိတ်ရက်စွဲ', 'Date the expense occurred; defaults to today.', 'အသုံးစရိတ် ဖြစ်ပေါ်သည့်ရက် ဖြစ်ပါသည်။ မဖြည့်လျှင် ယနေ့ရက် သုံးပါသည်။', 'optional', ['date']), $note,
            ]),
            'purchase' => $form('Purchase', 'အဝယ်', 'purchases', [$items, $productId,
                $f('items.*.quantity', 'Paid quantity', 'ဝယ်ယူအရေအတွက်', 'Paid quantity in the selected purchase unit; must be greater than zero.', 'ရွေးထားသော ဝယ်ယူယူနစ်ဖြင့် ငွေပေးဝယ်ယူသည့် အရေအတွက် ဖြစ်ပြီး 0 ထက်ကြီးရပါမည်။', 'required', ['numeric', 'gt:0'], 2),
                $f('items.*.unit_name', 'Unit', 'ယူနစ်', 'The selected product purchase/base unit.', 'ကုန်ပစ္စည်း၏ ရွေးထားသော ဝယ်ယူ သို့မဟုတ် အခြေခံယူနစ် ဖြစ်ပါသည်။', 'optional', ['string', 'max:50']),
                $f('items.*.unit_cost', 'Unit cost', 'တစ်ယူနစ်အရင်းဈေး', 'Cost of one selected purchase unit. A box price belongs here when buying boxes.', 'ရွေးထားသော ဝယ်ယူယူနစ်တစ်ခု၏ အရင်းဈေး ဖြစ်ပါသည်။ ဘူးဖြင့်ဝယ်လျှင် တစ်ဘူးဈေး ဖြည့်ပါ။', 'required', ['integer', 'min:0'], 12000),
                $f('items.*.foc_quantity', 'FOC quantity', 'အခမဲ့ရရှိအရေအတွက်', 'Extra free quantity in the selected unit.', 'ရွေးထားသော ယူနစ်ဖြင့် အပို အခမဲ့ရရှိသော အရေအတွက် ဖြစ်ပါသည်။', 'optional', ['numeric', 'min:0'], 1),
                $f('supplier_id', 'Saved supplier', 'သိမ်းထားသောပေးသွင်းသူ', 'Select a saved active supplier. Required if any amount remains unpaid.', 'သိမ်းထားသော active ပေးသွင်းသူကို ရွေးပါ။ ပေးချေရန်ငွေ ကျန်လျှင် မဖြစ်မနေ လိုပါသည်။', 'conditional', ['integer', 'existing_active_supplier', 'required_for_purchase_credit']),
                $f('supplier_name', 'Supplier name', 'ပေးသွင်းသူအမည်', 'Free-text name when no saved supplier is selected; it cannot replace a saved supplier for credit.', 'သိမ်းထားသော ပေးသွင်းသူ မရွေးလျှင် ဖြည့်သည့်အမည် ဖြစ်ပါသည်။ အကြွေးအတွက် saved supplier ကို အစားမထိုးနိုင်ပါ။', 'optional', ['string', 'max:255']),
                $f('reference_no', 'Reference', 'အညွှန်းအမှတ်', 'Supplier invoice or reference number.', 'ပေးသွင်းသူ၏ ဘောင်ချာ သို့မဟုတ် အညွှန်းအမှတ် ဖြစ်ပါသည်။', 'optional', ['string', 'max:255']),
                $f('purchase_date', 'Purchase date', 'ဝယ်ယူရက်စွဲ', 'Purchase date in year-month-day format.', 'နှစ်-လ-ရက် ပုံစံဖြင့် ဝယ်ယူရက်စွဲ ဖြစ်ပါသည်။', 'optional', ['date_format:Y-m-d'], '2026-10-24'),
                $f('expected_delivery', 'Expected delivery', 'ရောက်ရှိမည့်ရက်', 'Expected receipt date in year-month-day format.', 'နှစ်-လ-ရက် ပုံစံဖြင့် ပစ္စည်းရောက်ရှိရန် မျှော်မှန်းရက် ဖြစ်ပါသည်။', 'optional', ['date_format:Y-m-d']),
                array_replace($paid, ['validation' => ['integer', 'min:0', 'not_greater_than_purchase_total']]), $payment, $note,
            ], ['Paid amount cannot exceed purchase total. On create, omitted paid amount defaults to full payment; editing can preserve an existing payment.'], ['ပေးချေငွေသည် စုစုပေါင်းအဝယ်တန်ဖိုးထက် မများရပါ။ အသစ်ဖန်တီးရာတွင် paid amount မပို့လျှင် အပြည့်ပေးချေသည်ဟု သတ်မှတ်ပါသည်။ ပြင်ဆင်ချိန်တွင် လက်ရှိပေးချေမှုကို ထိန်းထားနိုင်ပါသည်။']),
            'sale' => $form('POS checkout', 'POS ငွေရှင်းရန်', 'sales', [$items, $productId,
                $f('items.*.quantity', 'Paid quantity', 'ရောင်းချအရေအတွက်', 'Paid units. Paid quantity plus FOC quantity must be greater than zero.', 'ငွေပေးရောင်းချသည့် အရေအတွက် ဖြစ်ပါသည်။ ဤအရေအတွက်နှင့် FOC စုစုပေါင်းသည် 0 ထက်ကြီးရပါမည်။', 'required', ['numeric', 'min:0', 'quantity_plus_foc_gt:0'], 2),
                $f('items.*.foc_quantity', 'FOC quantity', 'အခမဲ့ပေးအရေအတွက်', 'Free units included in stock deduction.', 'လက်ကျန်မှ လျှော့မည့် အခမဲ့ပေးသော အရေအတွက် ဖြစ်ပါသည်။', 'optional', ['numeric', 'min:0'], 1),
                $f('items.*.unit_price', 'Unit price', 'တစ်ယူနစ်ရောင်းဈေး', 'Price of one paid base unit.', 'ငွေပေးရောင်းချသော အခြေခံယူနစ်တစ်ခု၏ ဈေး ဖြစ်ပါသည်။', 'required', ['integer', 'min:0'], 1500),
                $f('items.*.price_type', 'Price type', 'ဈေးနှုန်းအမျိုးအစား', 'Selected product price type.', 'ရွေးထားသော ကုန်ပစ္စည်းဈေးနှုန်းအမျိုးအစား ဖြစ်ပါသည်။', 'optional', ['string', 'max:50'], 'Retail'),
                $f('discount', 'Discount', 'လျှော့ငွေ', 'Whole-number discount amount, not a percentage. The server caps it at subtotal.', 'ရာခိုင်နှုန်း မဟုတ်ဘဲ လျှော့ပေးသည့် ကိန်းပြည့် ငွေပမာဏ ဖြစ်ပါသည်။ Server က subtotal ထက် မကျော်အောင် ကန့်သတ်ပါသည်။', 'optional', ['integer', 'min:0'], 500),
                $f('payment_type', 'Payment type', 'ပေးချေမှုအမျိုးအစား', 'Cash or credit preference. The server derives the final type from any unpaid balance.', 'cash သို့မဟုတ် credit ဖြစ်ပါသည်။ နောက်ဆုံးအမျိုးအစားကို ပေးချေရန် ကျန်ငွေပေါ်မူတည်၍ server က တွက်ပါသည်။', 'optional', ['in:cash,credit'], 'cash'),
                $paid, $payment,
                $f('customer_id', 'Saved customer', 'သိမ်းထားသောဖောက်သည်', 'Select an existing customer when any amount remains unpaid.', 'ငွေပေးချေရန် ကျန်နေပါက သိမ်းထားသော ဖောက်သည်ကို ရွေးရပါမည်။', 'conditional', ['integer', 'existing_customer', 'required_for_unpaid_balance']),
                $f('admin_pin', 'Admin PIN', 'Admin PIN', 'Only enter an authorized PIN in the app when a protected action requests it. Never send it to support chat.', 'ခွင့်ပြုချက်လိုသော လုပ်ဆောင်ချက်က တောင်းသည့်အခါ app ထဲတွင်သာ ခွင့်ပြုထားသော PIN ဖြည့်ပါ။ Support chat သို့ မပို့ပါနှင့်။', 'conditional', ['string', 'protected_action_only'], null, true),
            ], ['Offline sales must be fully paid and cannot use customer credit. Check saved sales after a timeout before repeating checkout.'], ['Offline အရောင်းကို အပြည့်ပေးချေရပြီး ဖောက်သည်အကြွေး မသုံးနိုင်ပါ။ Timeout ပြီးနောက် ထပ်မရှင်းမီ သိမ်းပြီးသော အရောင်း ရှိမရှိ စစ်ပါ။']),
            'subscription_request' => $form('Subscription payment request', 'ပက်ကေ့ချ်ငွေပေးချေမှုတောင်းဆိုချက်', 'billing', [
                $f('subscription_plan_id', 'Package', 'ပက်ကေ့ချ်', 'Select a currently active public non-system package; do not type an arbitrary ID.', 'လက်ရှိ ရရှိနိုင်သော public ပက်ကေ့ချ်ကို ရွေးပါ။ ID ကို ခန့်မှန်း မဖြည့်ပါနှင့်။', 'required', ['active_public_non_system_plan']),
                $f('payment_method_id', 'Payment account', 'ငွေလက်ခံအကောင့်', 'Select an active subscription recipient, not a shop order payment account.', 'ဆိုင်အော်ဒါငွေလက်ခံအကောင့် မဟုတ်ဘဲ active subscription ငွေလက်ခံအကောင့်ကို ရွေးပါ။', 'required', ['active_subscription_payment_method']),
                $f('payment_screenshot', 'Payment screenshot', 'ငွေလွှဲအထောက်အထား', 'Upload the original payment image privately in the app. It is evidence for review, not automatic approval.', 'ငွေလွှဲပုံမူရင်းကို app မှတစ်ဆင့် သီးသန့်တင်ပါ။ Review အတွက် အထောက်အထားသာဖြစ်ပြီး အလိုအလျောက် approval မဟုတ်ပါ။', 'required', ['image', 'jpeg,jpg,png,webp', 'max_kb:5120'], null, true),
                $f('message', 'Message', 'စာတို', 'Optional explanation for payment review; exclude passwords or OTPs.', 'ငွေပေးချေမှုစစ်ဆေးရန် လိုအပ်သော ရှင်းပြချက်ဖြစ်ပြီး စကားဝှက်၊ OTP မထည့်ပါနှင့်။', 'optional', ['string', 'max:1000']),
            ]),
            'refund_request' => $form('Subscription refund request (support)', 'ပက်ကေ့ချ်ငွေပြန်အမ်းရန် တောင်းဆိုချက်', 'billing', [
                $f('payment_id', 'Original payment', 'မူလငွေပေးချေမှု', 'A subscription payment belonging to your business; this is not a Shop order refund form.', 'မိမိလုပ်ငန်း၏ ပက်ကေ့ချ်ငွေပေးချေမှု ဖြစ်ပါသည်။ Shop အော်ဒါငွေပြန်အမ်း form မဟုတ်ပါ။', 'required', ['integer', 'owned_subscription_payment']),
                $f('amount', 'Requested amount', 'တောင်းဆိုငွေပမာဏ', 'Positive whole-number amount in the original payment currency. Existing pending, approved and transferred refunds reduce the available balance.', 'မူလငွေကြေးအတိုင်း 0 ထက်ကြီးသော ကိန်းပြည့်ပမာဏ ဖြစ်ပါသည်။ Pending၊ approved၊ transferred refund များကို နုတ်ပြီး ကျန်ငွေထက် မကျော်ရပါ။', 'required', ['integer', 'min:1', 'not_greater_than_unreserved_payment_balance']),
                $f('reason', 'Reason', 'အကြောင်းပြချက်', 'Explain why you request a refund, with at least five non-blank characters.', 'ငွေပြန်အမ်းတောင်းဆိုရသည့် အကြောင်းပြချက်ကို စာလုံးအနည်းဆုံး ၅ လုံးဖြင့် ရှင်းပြပါ။', 'required', ['string', 'trimmed_min:5', 'max:2000']),
            ], ['This describes the support refund request fields; it does not claim an app refund screen or initiate a bank transfer.'], ['Support မှ ငွေပြန်အမ်းတောင်းဆိုချက်၏ field များကို ရှင်းပြခြင်း ဖြစ်ပါသည်။ App တွင် သီးခြား refund screen ရှိသည်ဟု မဆိုလိုဘဲ ဘဏ်ငွေလွှဲလည်း မလုပ်ပါ။']),
        ];
    }
}

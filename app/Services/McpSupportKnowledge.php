<?php

namespace App\Services;

use App\Http\Controllers\LandingController;
use App\Models\AppRelease;
use App\Support\PlatformContacts;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class McpSupportKnowledge
{
    public function articles(): array
    {
        $aliases = [
            'start' => 'စတင် အသုံးပြု', 'products' => 'ကုန်ပစ္စည်း စတော့ stock barcode ဈေးနှုန်း',
            'sales' => 'အရောင်း ငွေရှင်း ခြင်း checkout cart', 'purchases' => 'အဝယ် ဝယ်ယူ',
            'customers' => 'ဖောက်သည် အကြွေး', 'suppliers' => 'ပေးသွင်းသူ', 'expenses' => 'အသုံးစရိတ်',
            'transactions' => 'မှတ်တမ်း ဘောင်ချာ', 'reports' => 'အစီရင်ခံစာ', 'settings' => 'ဆက်တင် receipt',
            'printing' => 'ပရင်တာ ပုံနှိပ် printer', 'offline' => 'အင်တာနက် sync',
            'access' => 'ဝန်ထမ်း ခွင့်ပြုချက် permission', 'billing' => 'ပက်ကေ့ချ် ငွေလွှဲ subscription',
            'data' => 'အရန်သိမ်း backup update', 'troubleshooting' => 'ပြဿနာ error 429 login',
        ];
        $articles = [];
        foreach (LandingController::TOPICS as $id => [$title, $summary]) {
            $html = view('landing.docs.'.$id)->render();
            $html = preg_replace('/<\/(?:td|th)>/i', '$0 | ', $html);
            $html = preg_replace('/<br\s*\/?\s*>/i', "\n", $html);
            $text = html_entity_decode(strip_tags(preg_replace('/<\/(?:p|li|h[1-6]|tr)>/i', "$0\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $articles[$id] = ['article_id' => $id, 'title' => $title, 'summary' => $summary, 'app' => 'mkpos',
                'content_language' => 'en', 'content' => trim($text), 'keywords' => $aliases[$id] ?? '',
                'source_url' => route('documentation.show', $id)];
        }
        foreach (config('mcp_support.shop_guides', []) as $id => $guide) {
            $articles[$id] = ['article_id' => $id, 'app' => 'shop', 'content_language' => 'en', ...$guide,
                'source_url' => rtrim(config('clients.shop_url') ?: 'https://shop.mkposmyanmar.com', '/').'/#/account'];
        }

        return $articles;
    }

    public function run(string $tool, array $data): array
    {
        if (in_array($tool, ['get_product_import_template', 'validate_product_import'], true)) {
            abort_if(($data['app'] ?? 'business_web') === 'shop', 422, 'Product CSV import belongs to the Business apps, not the customer Shop app.');
            $validator = app(McpProductImportValidator::class);
            return $tool === 'get_product_import_template' ? $validator->template($data) : $validator->check($data);
        }
        if ($tool === 'get_form_field_guide') {
            return app(McpFormFieldGuide::class)->explain($data);
        }
        $app = $data['app'] ?? 'business_web';
        if ($tool === 'search_help_articles') {
            $words = preg_split('/\s+/u', mb_strtolower(trim($data['query'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $items = array_values(array_filter($this->articles(), function ($article) use ($words, $app) {
                if (($app === 'shop') !== ($article['app'] === 'shop')) {
                    return false;
                }
                $haystack = mb_strtolower(implode(' ', [$article['title'], $article['summary'], $article['content'], $article['keywords']]));

                return collect($words)->every(fn ($word) => str_contains($haystack, $word));
            }));
            $limit = (int) ($data['limit'] ?? 5);
            $offset = (int) ($data['offset'] ?? 0);
            $page = array_map(fn ($article) => array_diff_key($article, array_flip(['content', 'keywords'])), array_slice($items, $offset, $limit));

            return ['items' => $page, 'has_more' => $offset + $limit < count($items),
                'next_offset' => $offset + $limit < count($items) ? $offset + $limit : null,
                'note' => 'No match is not a failure. Try shorter English/Myanmar keywords or get_video_tutorials.'];
        }
        if ($tool === 'get_help_article') {
            $article = $this->articles()[$data['article_id']] ?? null;
            abort_unless($article, 404, 'Help article not found.');
            unset($article['keywords']);

            return ['article' => $article];
        }
        if ($tool === 'get_troubleshooting_guide') {
            $guide = config('mcp_support.troubleshooting.'.$data['topic']);
            abort_unless($guide, 404, 'Unknown troubleshooting topic. Use login, checkout, 429, printer, offline, barcode, notifications, permissions, product_form, cart, address, orders, chat, language or updates.');

            return ['app' => $app, 'topic' => $data['topic'], 'steps' => $guide,
                'note' => 'Ask for the exact app, platform, screen and error. Keep unsent drafts and offline sales. Never clear app data, reinstall or retry a possibly completed checkout before checking its status.',
                'source_url' => route('documentation.show', 'troubleshooting')];
        }
        if ($tool === 'get_supported_devices') {
            $article = $this->articles()['printing'];

            return ['app' => $app, 'printing_guide' => $article,
                'android_minimum_sdk' => 24, 'android_minimum_version' => 'Android 7.0',
                'windows_minimum_version' => 'Windows 10 (current Electron build)',
                'browsers' => 'Use a current supported browser. Browser print depends on an installed driver or print service.',
                'note' => 'Shop is a browser storefront; MKPOS printer features do not imply Shop printer support. Test the exact printer, transport and device before promising compatibility.'];
        }
        if ($tool === 'get_support_contacts') {
            return ['contacts' => PlatformContacts::all(), 'support_hours' => config('mcp_support.support_hours'),
                'note' => 'Null support_hours means not configured. These links do not contact a human or create a ticket.'];
        }
        if ($tool === 'get_subscription_payment_methods') {
            return ['items' => DB::table('payment_methods')->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'bank', 'account_name', 'account_no'])->all(),
                'note' => 'Subscription payment recipients only. Recheck before sharing; an empty list means no active recipient. Never substitute a shop order payment account.'];
        }
        if ($tool === 'get_latest_app_versions') {
            $items = [];
            foreach (AppRelease::whereIn('platform', AppRelease::PLATFORMS)->get() as $release) {
                $available = Storage::disk('local')->exists($release->file_path);
                $items[] = ['platform' => $release->platform, 'version' => $release->version,
                    'release_notes' => $release->release_notes, 'published_at' => $release->published_at?->toIso8601String(),
                    'status' => $available ? 'available' : 'file_missing',
                    'download_url' => $available ? route('downloads.show', ['platform' => $release->platform]) : null];
            }

            return ['releases' => $items, 'web_versions' => config('mcp_support.published_web_versions', []),
                'note' => 'Published release records only. A local build is not a deployed release. Missing web version means unknown; ask for the installed version before comparing.'];
        }
        if ($tool === 'get_service_status') {
            try {
                DB::select('SELECT 1');
                $database = 'reachable';
            } catch (QueryException $e) {
                report($e);
                $database = 'unavailable';
            }

            return ['database' => $database, 'components' => config('mcp_support.components'),
                'incidents' => config('mcp_support.incidents', []), 'retry_after' => 60,
                'note' => 'Database status is a local observation only. Remote API, socket and app status is unknown unless explicitly maintained in components. An empty incident list is not proof that services are healthy.'];
        }
        abort(404, 'Unknown public support tool.');
    }
}

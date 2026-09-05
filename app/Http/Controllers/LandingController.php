<?php

namespace App\Http\Controllers;

use App\Models\AppRelease;
use App\Models\Tutorial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LandingController extends Controller
{
    public const TOPICS = [
        'start' => ['Getting started', 'Prepare your shop, products, stock and first receipt.'],
        'products' => ['Products & stock', 'Create products, understand units, set prices, import CSV and correct stock.'],
        'sales' => ['Selling & checkout', 'Scan products, handle discounts, cash, change, credit and receipts.'],
        'purchases' => ['Receiving purchases', 'Receive supplier stock, enter carton costs, free goods and part payments.'],
        'customers' => ['Customers & credit', 'Create customer accounts, collect repayments and check statements.'],
        'suppliers' => ['Suppliers & payments', 'Manage supplier records, outstanding balances and payment directions.'],
        'expenses' => ['Business expenses', 'Record running costs and find or correct an expense.'],
        'transactions' => ['Transaction history', 'Find receipts, reprint documents and safely correct saved records.'],
        'reports' => ['Reports & daily closing', 'Understand report totals, reconcile payments and close the day.'],
        'settings' => ['Shop & receipt settings', 'Configure business details, payment methods, pricing and receipt layout.'],
        'printing' => ['Printing', 'Web, Windows and Android printer setup and limitations.'],
        'offline' => ['Offline & sync', 'Prepare cached products and synchronize pending sales safely.'],
        'access' => ['Staff access', 'Permissions, protected actions and till handover.'],
        'billing' => ['Packages & billing', 'Understand the trial, submit a package request and verify activation.'],
        'data' => ['Backups & updates', 'Protect business records and update devices safely.'],
        'troubleshooting' => ['Troubleshooting & glossary', 'Resolve common problems and learn the terms used throughout MKPOS.'],
    ];

    public function documentation(?string $topic = null)
    {
        abort_if($topic !== null && !array_key_exists($topic, self::TOPICS), 404);

        return view('landing.guide', ['topics' => self::TOPICS, 'topic' => $topic]);
    }

    public function plans()
    {
        $plans = DB::table('subscription_plans')
            ->where('is_active', true)->where('is_public', true)->where('is_system', false)
            ->orderBy('sort_order')->orderBy('price')
            ->get(['name', 'description', 'price', 'currency', 'duration_days', 'features']);

        return view('landing.plans', compact('plans'));
    }

    public function index()
    {
        return view('landing', [
            'releases' => AppRelease::query()->get()->keyBy('platform'),
            'topics' => self::TOPICS,
            'tutorials' => Tutorial::where('is_published', true)->orderBy('sort_order')->orderByDesc('id')->limit(6)->get(),
        ]);
    }

    public function tutorials()
    {
        return view('landing.tutorials', [
            'tutorials' => Tutorial::where('is_published', true)->orderBy('sort_order')->orderByDesc('id')->paginate(12),
        ]);
    }

    public function tutorialThumbnail(Tutorial $tutorial)
    {
        abort_unless($tutorial->is_published && $tutorial->thumbnail_path && Storage::disk('local')->exists($tutorial->thumbnail_path), 404);

        return response()->file(Storage::disk('local')->path($tutorial->thumbnail_path), [
            'Cache-Control' => 'no-cache, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

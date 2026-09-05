@extends('landing.layout')
@section('title', 'MKPOS — Product overview')
@section('content')
    <main>
        <section class="hero">
            <div class="hero-glow hero-glow-one"></div>
            <div class="hero-glow hero-glow-two"></div>
            <div class="container hero-grid">
                <div class="hero-copy">
                    <div class="eyebrow"><span></span>Built for growing local businesses</div>
                    <h1>Simple point of sale.<br><em>Complete business control.</em></h1>
                    <p class="hero-lead">Sell faster, understand your stock, and keep customer and supplier credit clear—all from one dependable workspace.</p>
                    <div class="hero-actions">
                        <a class="button button-primary" href="{{ url('/app') }}/#/signup">Start your free trial <svg><use href="#icon-arrow"/></svg></a>
                        <a class="button button-secondary" href="#documentation">Read documentation</a>
                    </div>
                    <div class="hero-assurances">
                        <span><svg><use href="#icon-check"/></svg>One-month free trial</span>
                        <span><svg><use href="#icon-check"/></svg>Web, Windows and Android</span>
                        <span><svg><use href="#icon-check"/></svg>Myanmar-ready</span>
                    </div>
                </div>

                <div class="product-stage" id="product-preview" aria-label="Illustrative MKPOS dashboard preview">
                    <div class="stage-orbit orbit-one"></div>
                    <div class="stage-orbit orbit-two"></div>
                    <div class="dashboard-window">
                        <div class="window-topbar">
                            <div class="window-brand"><img src="{{ asset('app/branding/mktransparenticon.png') }}" alt=""><strong>MKPOS</strong></div>
                            <div class="window-search"><span></span>Search products or barcode</div>
                            <div class="avatar">KL</div>
                        </div>
                        <div class="window-body">
                            <aside class="preview-sidebar" aria-hidden="true">
                                <i class="active"></i><i></i><i></i><i></i><i></i><i></i>
                            </aside>
                            <div class="preview-content">
                                <div class="preview-heading"><div><small>GOOD MORNING</small><strong>Business overview</strong></div><span>Today</span></div>
                                <div class="metric-grid">
                                    <article><span class="metric-icon lime"><svg><use href="#icon-receipt"/></svg></span><small>Today’s sales</small><strong>486,500 <b>Ks</b></strong><em>+12.4% this week</em></article>
                                    <article><span class="metric-icon blue"><svg><use href="#icon-box"/></svg></span><small>Stock units</small><strong>1,284</strong><em>Across 96 products</em></article>
                                    <article><span class="metric-icon amber"><svg><use href="#icon-wallet"/></svg></span><small>To collect</small><strong>124,000 <b>Ks</b></strong><em>7 open accounts</em></article>
                                </div>
                                <div class="preview-panels">
                                    <article class="sales-chart">
                                        <div><strong>Sales performance</strong><small>Last 7 days</small></div>
                                        <div class="chart-area" aria-hidden="true">
                                            <svg viewBox="0 0 500 170" preserveAspectRatio="none"><defs><linearGradient id="chartFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1c8b69" stop-opacity=".26"/><stop offset="1" stop-color="#1c8b69" stop-opacity="0"/></linearGradient></defs><path class="chart-fill" d="M0 150 C55 135 55 115 105 122 S165 82 215 96 S282 58 330 70 S410 18 500 31 L500 170 L0 170Z"/><path class="chart-line" d="M0 150 C55 135 55 115 105 122 S165 82 215 96 S282 58 330 70 S410 18 500 31"/></svg>
                                        </div>
                                        <div class="chart-days"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
                                    </article>
                                    <article class="activity-card">
                                        <div><strong>Live activity</strong><small>Just now</small></div>
                                        <ul>
                                            <li><span class="activity-dot green"></span><div><strong>Sale completed</strong><small>Receipt R260828-0042</small></div><b>18,500 Ks</b></li>
                                            <li><span class="activity-dot blue"></span><div><strong>Stock received</strong><small>Valley Water · 12 cartons</small></div></li>
                                            <li><span class="activity-dot amber"></span><div><strong>Credit collected</strong><small>Customer payment</small></div><b>25,000 Ks</b></li>
                                        </ul>
                                    </article>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="floating-card floating-stock"><span><svg><use href="#icon-box"/></svg></span><div><small>Low stock alerts</small><strong>3 products need attention</strong></div></div>
                    <div class="floating-card floating-sync"><span><svg><use href="#icon-check"/></svg></span><div><small>Workspace status</small><strong>Everything is up to date</strong></div></div>
                </div>
            </div>
        </section>

        <section class="proof-strip" aria-label="MKPOS capabilities">
            <div class="container">
                <span>ONE CONNECTED WORKSPACE</span>
                <div><b>Point of sale</b><i></i><b>Inventory</b><i></i><b>Credit accounts</b><i></i><b>Reporting</b><i></i><b>Multi-platform</b></div>
            </div>
        </section>

        <section class="section features" id="features">
            <div class="container">
                <div class="section-heading">
                    <div><span class="section-kicker">Everything in its place</span><h2>Less administration.<br>More confident decisions.</h2></div>
                    <p>MKPOS connects daily selling with the records behind it, so your team works faster and you always know where the business stands.</p>
                </div>
                <div class="feature-grid">
                    <article class="feature-card feature-card-large dark-card">
                        <div class="feature-icon"><svg><use href="#icon-receipt"/></svg></div>
                        <span>FAST CHECKOUT</span>
                        <h3>Turn every sale into a clear record.</h3>
                        <p>Search or scan products, choose a price type, accept multiple payment methods, record credit, and print a professional receipt.</p>
                        <div class="mini-checkout" aria-hidden="true"><div class="mini-product"><span>01</span><div><strong>Premium Water</strong><small>6 Bottles · Retail</small></div><b>6,000 Ks</b></div><div class="mini-total"><span>Total</span><strong>6,000 Ks</strong></div><button>Complete sale</button></div>
                    </article>
                    <article class="feature-card inventory-card">
                        <div class="feature-icon"><svg><use href="#icon-box"/></svg></div>
                        <span>INVENTORY</span>
                        <h3>See stock before it becomes a problem.</h3>
                        <p>Track quantities in your real selling units, purchase in cartons or packs, and act on low-stock alerts early.</p>
                        <div class="stock-bars" aria-hidden="true"><i style="--stock:88%"></i><i style="--stock:64%"></i><i class="warning" style="--stock:24%"></i><i style="--stock:72%"></i><i class="danger" style="--stock:10%"></i></div>
                    </article>
                    <article class="feature-card">
                        <div class="feature-icon"><svg><use href="#icon-users"/></svg></div>
                        <span>CUSTOMER ACCOUNTS</span>
                        <h3>Know exactly what is still to collect.</h3>
                        <p>Keep credit sales, repayments, refunds and printable statements together in one customer account.</p>
                        <ul class="feature-list"><li><svg><use href="#icon-check"/></svg>Clear running balances</li><li><svg><use href="#icon-check"/></svg>Complete payment history</li></ul>
                    </article>
                    <article class="feature-card">
                        <div class="feature-icon"><svg><use href="#icon-truck"/></svg></div>
                        <span>SUPPLIERS & PURCHASES</span>
                        <h3>Control purchases and supplier credit.</h3>
                        <p>Receive stock, capture changing unit costs, record amounts paid, and follow every supplier balance.</p>
                        <ul class="feature-list"><li><svg><use href="#icon-check"/></svg>Purchase-unit conversion</li><li><svg><use href="#icon-check"/></svg>Supplier payment records</li></ul>
                    </article>
                    <article class="feature-card">
                        <div class="feature-icon"><svg><use href="#icon-monitor"/></svg></div>
                        <span>COUNTER & DEVICE TOOLS</span>
                        <h3>Keep the counter ready to work.</h3>
                        <p>Use native printer profiles, receipt customization and eligible offline sales. Give staff access to the tools their role needs.</p>
                        <ul class="feature-list"><li><svg><use href="#icon-check"/></svg>Device-specific printer setup</li><li><svg><use href="#icon-check"/></svg>Pending-sale synchronization</li></ul>
                    </article>
                    <article class="feature-card feature-card-wide report-card">
                        <div class="report-copy"><div class="feature-icon"><svg><use href="#icon-chart"/></svg></div><span>BUSINESS REPORTS</span><h3>Move from transactions to insight.</h3><p>Review sales, expenses, credit exposure, stock value and payment movement in a single business summary.</p></div>
                        <div class="report-visual" aria-hidden="true"><div><small>Net sales</small><strong>2.48M Ks</strong><span>+18.2%</span></div><svg viewBox="0 0 520 160" preserveAspectRatio="none"><path d="M0 142 C54 136 70 112 112 119 S177 80 221 92 S287 53 326 64 S407 18 520 24"/><circle cx="520" cy="24" r="7"/></svg><div class="report-columns"><i style="--h:34%"></i><i style="--h:48%"></i><i style="--h:44%"></i><i style="--h:66%"></i><i style="--h:60%"></i><i style="--h:81%"></i><i style="--h:92%"></i></div></div>
                    </article>
                </div>
            </div>
        </section>

        <section class="section workflow" id="how-it-works">
            <div class="container workflow-grid">
                <div class="workflow-intro"><span class="section-kicker light">A better daily rhythm</span><h2>From first product to first report in three clear steps.</h2><p>No complicated rollout. Set up the essentials, bring in your products, and let each transaction keep the rest of the business current.</p><a class="inline-link" href="{{ url('/app') }}/#/signup">Create your workspace <svg><use href="#icon-arrow"/></svg></a></div>
                <ol class="workflow-steps">
                    <li><span>01</span><div><h3>Create your business</h3><p>Register your shop and choose the settings your team will use every day.</p></div></li>
                    <li><span>02</span><div><h3>Build your catalogue</h3><p>Add products, selling prices, purchase units and opening stock.</p></div></li>
                    <li><span>03</span><div><h3>Sell and stay informed</h3><p>Every sale, purchase and payment updates the records that power your reports.</p></div></li>
                </ol>
            </div>
        </section>

        <section class="section platforms" id="platforms">
            <div class="container">
                <div class="center-heading"><span class="section-kicker">Work your way</span><h2>One business. Every screen you need.</h2><p>Use MKPOS where the work happens—from the counter to the stockroom to your desk.</p></div>
                <div class="platform-grid">
                    <article><span><svg><use href="#icon-globe"/></svg></span><h3>Web application</h3><p>Open your workspace in a modern browser and manage the full business from anywhere.</p><div class="platform-action"><small>Always current</small><a class="platform-download-button secondary" href="{{ url('/app') }}/#/login">Open web app <svg><use href="#icon-arrow"/></svg></a></div></article>
                    @php($windowsRelease = $releases->get('windows'))
                    <article class="featured"><span><svg><use href="#icon-monitor"/></svg></span><h3>Windows desktop</h3><p>A focused checkout experience with support for eligible offline cash sales and later synchronization.</p><div class="platform-action">@if($windowsRelease)<small>Version {{ $windowsRelease->version }} · {{ number_format($windowsRelease->file_size / 1048576, 1) }} MB</small><a class="platform-download-button" href="{{ route('downloads.show', ['platform' => 'windows']) }}">Download for Windows <svg><use href="#icon-arrow"/></svg></a>@else<small>Desktop download coming soon</small><span class="platform-download-unavailable">Not yet published</span>@endif</div></article>
                    @php($androidRelease = $releases->get('android'))
                    <article><span><svg><use href="#icon-phone"/></svg></span><h3>Android mobile</h3><p>Carry products, purchases, accounts and reports with you in a mobile-first application.</p><div class="platform-action">@if($androidRelease)<small>Version {{ $androidRelease->version }} · {{ number_format($androidRelease->file_size / 1048576, 1) }} MB</small><a class="platform-download-button secondary" href="{{ route('downloads.show', ['platform' => 'android']) }}">Download Android APK <svg><use href="#icon-arrow"/></svg></a>@else<small>Android download coming soon</small><span class="platform-download-unavailable">Not yet published</span>@endif</div></article>
                </div>
            </div>
        </section>

        @include('landing.documentation-index')
        <section class="section" id="tutorials"><div class="container">
            <div class="tutorial-section-heading"><div><span class="section-kicker">Learn by watching</span><h2>MKPOS video tutorials</h2><p>Follow practical walkthroughs alongside our step-by-step documentation.</p></div><a class="button button-small" href="{{ route('tutorials.index') }}">All tutorials →</a></div>
            @include('landing.tutorial-cards', ['tutorials' => $tutorials ?? collect(), 'compact' => true])
        </div></section>
<section class="section"><div class="container docs-card"><h2>Package plans</h2><p>Compare published prices, durations and features, starting with a one-month free trial.</p><a class="button button-primary" href="{{ route('plans.index') }}">View package plans</a></div></section>

        <section class="section security" id="security">
            <div class="container security-card">
                <div class="security-mark"><svg><use href="#icon-shield"/></svg><i></i><i></i></div>
                <div class="security-copy"><span class="section-kicker light">Designed for dependable operations</span><h2>Your team sees what they need. Your business stays separated.</h2><p>Each MKPOS workspace keeps its operational data scoped to the business, with role-based access for staff and protected administrative actions.</p></div>
                <div class="security-points"><span><svg><use href="#icon-check"/></svg>Business-isolated records</span><span><svg><use href="#icon-check"/></svg>Role-based staff access</span><span><svg><use href="#icon-check"/></svg>Protected admin actions</span><span><svg><use href="#icon-check"/></svg>Data backup capability</span></div>
            </div>
        </section>

        <section class="final-cta">
            <div class="container cta-card">
                <div class="cta-grid"></div>
                <div><span class="section-kicker light">Start with confidence</span><h2>Give your business a clearer operating system.</h2><p>Begin with a one-month free trial and bring sales, stock, purchases and accounts into one calm workspace.</p></div>
                <div class="cta-actions"><a class="button button-lime" href="{{ url('/app') }}/#/signup">Start free trial <svg><use href="#icon-arrow"/></svg></a><a href="{{ url('/app') }}/#/login">Already use MKPOS? Sign in</a></div>
            </div>
        </section>
    </main>

@endsection

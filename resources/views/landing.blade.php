@extends('landing.layout')
@section('title', 'MKPOS — Point of sale for local shops')
@section('content')
    <main class="landing-home">
        <section class="hero">
            <div class="hero-glow hero-glow-one"></div>
            <div class="hero-glow hero-glow-two"></div>
            <div class="container hero-grid">
                <div class="hero-copy">
                    <div class="eyebrow"><span></span>Point of sale for local shops</div>
                    <h1>Sales, stock and credit.<br><em>All in one place.</em></h1>
                    <p class="hero-lead">MKPOS helps you sell, manage stock, track payments and see reports in one app.</p>
                    <div class="hero-actions">
                        <a class="button button-primary" href="{{ url('/app') }}/#/signup">Start free trial <svg><use href="#icon-arrow"/></svg></a>
                        <a class="button button-secondary" href="#how-it-works">See how it works</a>
                    </div>
                    <div class="hero-assurances">
                        <span><svg><use href="#icon-check"/></svg>One-month free trial</span>
                        <span><svg><use href="#icon-check"/></svg>Web, Windows and Android</span>
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
                <span>YOUR DAILY WORK, CONNECTED</span>
                <div><b>Sell products</b><i></i><b>Check stock</b><i></i><b>Track credit</b><i></i><b>See reports</b></div>
            </div>
        </section>

        <section class="section workflow" id="how-it-works">
            <div class="container workflow-grid">
                <div class="workflow-intro"><span class="section-kicker light">Get started</span><h2>Set up. Add products. Start selling.</h2><p>MKPOS keeps your records current as you work.</p><a class="inline-link" href="{{ url('/app') }}/#/signup">Create your workspace <svg><use href="#icon-arrow"/></svg></a></div>
                <ol class="workflow-steps">
                    <li><span>01</span><div><h3>Create your business</h3><p>Register your shop and set up your team.</p></div></li>
                    <li><span>02</span><div><h3>Add your products</h3><p>Enter prices and opening stock.</p></div></li>
                    <li><span>03</span><div><h3>Start selling</h3><p>Sales and payments update your records automatically.</p></div></li>
                </ol>
            </div>
        </section>

        <section class="section features" id="features">
            <div class="container">
                <div class="section-heading">
                    <div><span class="section-kicker">One connected workspace</span><h2>Everything your shop needs.</h2></div>
                    <p>Each sale and purchase keeps your stock, balances and reports up to date.</p>
                </div>
                <div class="feature-grid">
                    <article class="feature-card feature-card-large dark-card">
                        <div class="feature-icon"><svg><use href="#icon-receipt"/></svg></div>
                        <span>FAST CHECKOUT</span>
                        <h3>Make sales in seconds.</h3>
                        <p>Scan products, take payment, record credit and print receipts.</p>
                        <div class="mini-checkout" aria-hidden="true"><div class="mini-product"><span>01</span><div><strong>Premium Water</strong><small>6 Bottles · Retail</small></div><b>6,000 Ks</b></div><div class="mini-total"><span>Total</span><strong>6,000 Ks</strong></div><button>Complete sale</button></div>
                    </article>
                    <article class="feature-card inventory-card">
                        <div class="feature-icon"><svg><use href="#icon-box"/></svg></div>
                        <span>INVENTORY</span>
                        <h3>Always know what is in stock.</h3>
                        <p>Track products in packs or pieces and spot low stock early.</p>
                        <div class="stock-bars" aria-hidden="true"><i style="--stock:88%"></i><i style="--stock:64%"></i><i class="warning" style="--stock:24%"></i><i style="--stock:72%"></i><i class="danger" style="--stock:10%"></i></div>
                    </article>
                    <article class="feature-card">
                        <div class="feature-icon"><svg><use href="#icon-users"/></svg></div>
                        <span>CUSTOMER ACCOUNTS</span>
                        <h3>See what customers owe.</h3>
                        <p>Keep credit sales and repayments in each customer account.</p>
                        <ul class="feature-list"><li><svg><use href="#icon-check"/></svg>Clear running balances</li><li><svg><use href="#icon-check"/></svg>Complete payment history</li></ul>
                    </article>
                    <article class="feature-card">
                        <div class="feature-icon"><svg><use href="#icon-truck"/></svg></div>
                        <span>SUPPLIERS & PURCHASES</span>
                        <h3>Manage purchases and suppliers.</h3>
                        <p>Receive stock, record payments and follow supplier balances.</p>
                        <ul class="feature-list"><li><svg><use href="#icon-check"/></svg>Purchase-unit conversion</li><li><svg><use href="#icon-check"/></svg>Supplier payment records</li></ul>
                    </article>
                    <article class="feature-card">
                        <div class="feature-icon"><svg><use href="#icon-monitor"/></svg></div>
                        <span>COUNTER & DEVICE TOOLS</span>
                        <h3>Keep checkout ready.</h3>
                        <p>Set up printers, receipts and staff access for your counter.</p>
                        <ul class="feature-list"><li><svg><use href="#icon-check"/></svg>Device-specific printer setup</li><li><svg><use href="#icon-check"/></svg>Pending-sale synchronization</li></ul>
                    </article>
                    <article class="feature-card feature-card-wide report-card">
                        <div class="report-copy"><div class="feature-icon"><svg><use href="#icon-chart"/></svg></div><span>BUSINESS REPORTS</span><h3>See how your business is doing.</h3><p>Review sales, expenses, credit and stock value together.</p></div>
                        <div class="report-visual" aria-hidden="true"><div><small>Net sales</small><strong>2.48M Ks</strong><span>+18.2%</span></div><svg viewBox="0 0 520 160" preserveAspectRatio="none"><path d="M0 142 C54 136 70 112 112 119 S177 80 221 92 S287 53 326 64 S407 18 520 24"/><circle cx="520" cy="24" r="7"/></svg><div class="report-columns"><i style="--h:34%"></i><i style="--h:48%"></i><i style="--h:44%"></i><i style="--h:66%"></i><i style="--h:60%"></i><i style="--h:81%"></i><i style="--h:92%"></i></div></div>
                    </article>
                </div>
            </div>
        </section>

        <section class="section platforms" id="platforms">
            <div class="container">
                <div class="center-heading"><span class="section-kicker">Use MKPOS anywhere</span><h2>Choose your device.</h2><p>Open it in a browser or install it on Windows or Android.</p></div>
                @php($windowsRelease = $releases->get('windows'))
                @php($windows32Release = $releases->get('windows32'))
                @php($androidRelease = $releases->get('android'))
                <div class="platform-grid">
                    <article>
                        <span><svg><use href="#icon-globe"/></svg></span>
                        <div class="platform-copy"><small class="platform-type">Browser</small><h3>Web desktop</h3><p>Full workspace for desktop and laptop browsers.</p></div>
                        <div class="platform-action"><small>Always current</small><a class="platform-download-button secondary" href="{{ url('/app') }}/#/login">Open desktop web <svg><use href="#icon-arrow"/></svg></a></div>
                    </article>
                    <article>
                        <span><svg><use href="#icon-phone"/></svg></span>
                        <div class="platform-copy"><small class="platform-type">Browser</small><h3>Web mobile</h3><p>Touch-friendly mobile access without an installation.</p></div>
                        <div class="platform-action"><small>Mobile optimized</small><a class="platform-download-button secondary" href="{{ url('/mobile') }}/#/login">Open mobile web <svg><use href="#icon-arrow"/></svg></a></div>
                    </article>
                    <article>
                        <span><svg><use href="#icon-phone"/></svg></span>
                        <div class="platform-copy"><small class="platform-type">Install</small><h3>Android</h3><p>Native mobile access with camera and barcode support.</p></div>
                        <div class="platform-action">@if($androidRelease)<small>Version {{ $androidRelease->version }} · {{ number_format($androidRelease->file_size / 1048576, 1) }} MB</small><a class="platform-download-button secondary" href="{{ route('downloads.show', ['platform' => 'android']) }}">Download APK <svg><use href="#icon-arrow"/></svg></a>@else<small>Android download coming soon</small><span class="platform-download-unavailable">Not yet published</span>@endif</div>
                    </article>
                    <article class="featured">
                        <span><svg><use href="#icon-monitor"/></svg></span>
                        <div class="platform-copy"><small class="platform-type">Recommended</small><h3>Windows 64-bit</h3><p>Desktop checkout for modern 64-bit Windows computers.</p></div>
                        <div class="platform-action">@if($windowsRelease)<small>Version {{ $windowsRelease->version }} · {{ number_format($windowsRelease->file_size / 1048576, 1) }} MB</small><a class="platform-download-button" href="{{ route('downloads.show', ['platform' => 'windows']) }}">Download 64-bit <svg><use href="#icon-arrow"/></svg></a>@else<small>64-bit download coming soon</small><span class="platform-download-unavailable">Not yet published</span>@endif</div>
                    </article>
                    <article>
                        <span><svg><use href="#icon-monitor"/></svg></span>
                        <div class="platform-copy"><small class="platform-type">Legacy support</small><h3>Windows 32-bit</h3><p>Compatible desktop build for older x86 Windows computers.</p></div>
                        <div class="platform-action">@if($windows32Release)<small>Version {{ $windows32Release->version }} · {{ number_format($windows32Release->file_size / 1048576, 1) }} MB</small><a class="platform-download-button secondary" href="{{ route('downloads.show', ['platform' => 'windows32']) }}">Download 32-bit <svg><use href="#icon-arrow"/></svg></a>@else<small>32-bit download coming soon</small><span class="platform-download-unavailable">Not yet published</span>@endif</div>
                    </article>
                </div>
            </div>
        </section>

        <section class="section pricing-section" id="pricing">
            <div class="container docs-card pricing-card">
                <div><span class="section-kicker">Simple pricing</span><h2>Try MKPOS free for one month.</h2><p>Choose a plan when you are ready.</p></div>
                <a class="button button-primary" href="{{ route('plans.index') }}">View pricing <svg><use href="#icon-arrow"/></svg></a>
            </div>
        </section>

        @include('landing.documentation-index')
        <section class="section" id="tutorials"><div class="container">
            <div class="tutorial-section-heading"><div><span class="section-kicker">Learn by watching</span><h2>Video tutorials</h2><p>Watch how to use MKPOS.</p></div><a class="button button-small" href="{{ route('tutorials.index') }}">All tutorials →</a></div>
            @include('landing.tutorial-cards', ['tutorials' => $tutorials ?? collect(), 'compact' => true])
        </div></section>
        <section class="section security" id="security">
            <div class="container security-card">
                <div class="security-mark"><svg><use href="#icon-shield"/></svg><i></i><i></i></div>
                <div class="security-copy"><span class="section-kicker light">Built for your business</span><h2>Keep your business records in the right hands.</h2><p>Staff access follows their roles, and each business has its own records.</p></div>
                <div class="security-points"><span><svg><use href="#icon-check"/></svg>Business-isolated records</span><span><svg><use href="#icon-check"/></svg>Role-based staff access</span><span><svg><use href="#icon-check"/></svg>Protected admin actions</span><span><svg><use href="#icon-check"/></svg>Data backup capability</span></div>
            </div>
        </section>

        <section class="final-cta">
            <div class="container cta-card">
                <div class="cta-grid"></div>
                <div><span class="section-kicker light">Ready to begin?</span><h2>Run your shop with MKPOS.</h2><p>Start with one month free.</p></div>
                <div class="cta-actions"><a class="button button-lime" href="{{ url('/app') }}/#/signup">Start free trial <svg><use href="#icon-arrow"/></svg></a><a href="{{ url('/app') }}/#/login">Already use MKPOS? Sign in</a></div>
            </div>
        </section>
    </main>

@endsection

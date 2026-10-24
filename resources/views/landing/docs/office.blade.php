<p class="manual-intro">MKPOS Office is the platform administration console. Authorized platform administrators use it to manage service access and platform finances. Shop owners manage their own staff and shop records in the Business app.</p>

<h2 id="administrators">Administrator accounts and access</h2>
<p>Open Administrators as a super administrator to create accounts, edit names and emails, set access levels, reset passwords or deactivate an account. Ordinary administrators handle daily platform operations, subscriptions and finance. Super administrators also manage administrator accounts and branding.</p>
<p>Deactivation preserves the account's historical attribution. The system protects your own super-admin account from self-deactivation or self-demotion and retains at least one active super administrator. Password changes and deactivation revoke tokens; sessions are checked when used.</p>

<h2 id="activity">Trace approvals and access changes</h2>
<p>Activity log records successful Office changes and sign-ins from this update onward. Search by administrator or target, filter by administrator, and open View action details to inspect the recorded action and timestamp. Passwords, tokens and uploaded image contents are excluded.</p>
<p>Subscription requests retain the reviewing administrator. Payment records identify the recording administrator, and billing history identifies the administrator who last changed access. Earlier actions are not reconstructed retroactively.</p>

<h2 id="receiving-books">Bank books and subscription receipts</h2>
<p>Use Bank books to add or rename books, archive books with history and delete unused books. Choose Receive subscriptions here to set the default book for future subscription receipts. During approval, assignment or renewal, use Subscription receiving book to override that default for the individual payment.</p>
<p>Existing receipts keep their original book when the default changes. The internal receiving book is separate from the customer-facing payment instructions. Referenced books cannot be deleted; archive them instead. Change the subscription default before archiving its current book. Archived balances remain included in the financial history.</p>

<h2 id="founders">Founder shares and financial history</h2>
<p>In Financial book, open Founder settings to add founders, edit names and percentages, or remove founders from future allocations. Active shares must total 100%. Removing a founder archives their identity and preserves their earlier contributions, allocations and payouts.</p>
<p>Allocate profit from a funded book to record the active founders' shares as paid. Each allocation keeps its original names, percentages and amounts. Older unpaid allocations remain available for separate payouts. Transfers move funds between books without changing total cash or profit. Opening adjustments add to an existing balance; enter the adjustment amount rather than replacing the balance with the full bank-statement amount.</p>

<h2 id="branding">Theme, logo and web icons</h2>
<p>Super administrators use Branding to choose the primary theme color and upload or restore the logo, app icon and favicon. Images can be PNG, JPEG or WebP, up to 2 MB and 4096 pixels per dimension. Updated web clients receive the settings when opened or refreshed; the public website uses the same settings.</p>
<p>Packaged Windows and Android launcher icons and installed home-screen icons require an application release or reinstall.</p>

<h2 id="upgrades">Updating a running installation</h2>
<p>Take a full database backup and stop financial writes, approval integrations and background workers during the upgrade. From the Laravel directory, save a private financial snapshot with <code>php artisan office:financial-snapshot</code> before applying migrations. Run the printed <code>--verify=FILENAME.json</code> command afterward and resume writes only when original records and financial statistics match.</p>
<p>The upgrade keeps historical subscription receipts in their legacy book and preserves opening adjustments, transfers, expenses, founder history and payment dates. It does not create duplicate income or reset balances. The private snapshot supplements the database backup. If verification fails, keep writes stopped and investigate; do not recreate the database. Unsafe rollback is blocked when it would discard newer receipt routing or founder settings.</p>

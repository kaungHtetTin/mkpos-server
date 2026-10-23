<?php

return [
    'title' => 'What is MKPOS?',
    'summary' => 'MKPOS is a point-of-sale (POS) and shop management system. It helps shop owners and staff manage sales, products, stock, purchases, customer credit, supplier balances, expenses and business reports in one workspace.',
    'audience' => 'Shop owners, managers and cashiers who manage daily retail operations.',
    'capabilities' => [
        'sales' => ['title' => 'Sales and checkout', 'description' => 'Find or scan products, take payments, record credit sales and print receipts.'],
        'products' => ['title' => 'Products and stock', 'description' => 'Manage products, barcodes, units and selling prices; review stock movements, adjust stock and identify low-stock items.'],
        'purchases' => ['title' => 'Purchases', 'description' => 'Receive supplier stock, convert purchase units to selling units and record purchase costs and payments.'],
        'customers' => ['title' => 'Customer credit', 'description' => 'Manage customer records, credit sales, repayments, outstanding balances and statements.'],
        'suppliers' => ['title' => 'Suppliers', 'description' => 'Manage supplier records, payments and outstanding balances.'],
        'expenses' => ['title' => 'Expenses', 'description' => 'Record and review day-to-day business expenses.'],
        'reports' => ['title' => 'Reports', 'description' => 'Review sales, expenses, credit and stock value to understand business activity.'],
        'access' => ['title' => 'Staff access', 'description' => 'Control staff access through roles and permissions. Each business has its own records.'],
        'settings' => ['title' => 'Shop and receipt settings', 'description' => 'Configure shop details, receipt layout, payment methods and price types.'],
        'offline' => ['title' => 'Offline sales and synchronization', 'description' => 'On supported, prepared devices, record eligible fully paid sales offline and synchronize them when the server is reachable again.'],
        'data' => ['title' => 'Business backups', 'description' => 'Download and restore business backups with the required authority and a paid plan.'],
    ],
    'platforms' => [
        'Desktop and laptop web browsers.',
        'Mobile web browsers.',
        'Windows desktop and Android apps; check published downloads for build availability.',
    ],
    'limitations' => [
        'Feature access depends on staff permissions, subscription access, device and configuration. Do not assume every feature is available to every user.',
        'Offline mode supports eligible fully paid sales, not customer credit or every management screen. Products and prices must be cached while online; synchronization requires server access.',
        'Printing support depends on the application, device and printer connection.',
        'Backup download and restore require a paid plan. Restore can replace current business records.',
        'Use get_available_package_plans for current package prices and listed benefits. This overview does not promise package-specific entitlements or download availability.',
    ],
];

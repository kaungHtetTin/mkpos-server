<?php

return [
    'login' => [
        'title' => 'How to log in to MKPOS',
        'steps' => [
            'Open the MKPOS login link for desktop web or mobile web, or open your installed MKPOS app. Connect to the internet for sign-in.',
            'Enter your registered Email address and Password.',
            'On your own trusted device, you may select Keep me signed in. Leave it off on shared devices.',
            'Select Sign in to MKPOS on desktop, or Login on mobile, and wait for your workspace to open.',
            'If a package purchase or renewal screen appears, review your subscription access. Signing in alone does not activate a paid package.',
        ],
        'notes' => ['Check the email spelling and password if sign-in fails. Use Forgot password? for an owner account, or ask the business owner about staff access.'],
    ],
    'register' => [
        'title' => 'How to register a business account',
        'steps' => [
            'Open the MKPOS signup link, or select Create business account / Create account from the login screen.',
            'Enter your Business name, Owner name and Email address. Use an email inbox you can access.',
            'Choose a password with at least 8 characters and enter the same password in the confirmation field.',
            'Select Create business account on desktop, or Create account on mobile. Correct any validation errors shown.',
            'After successful registration you are signed in as the new business owner. Follow the access or billing screen shown, then prepare your shop settings and products.',
        ],
        'notes' => ['An email address can only be registered once across MKPOS. If it is already registered, sign in or recover the existing account.', 'Registration creates a new business; staff joining an existing business should ask its owner for access. Do not create another business to join a shop.'],
    ],
    'password_reset' => [
        'title' => 'How to reset a forgotten password',
        'steps' => [
            'Open the MKPOS login page and select Forgot password?, or use the password recovery link.',
            'Enter your registered business owner email address and select Send reset code.',
            'Check your inbox and spam folder for the 6-digit code. A generic confirmation does not prove an eligible account exists or that email was delivered.',
            'Enter the code within 10 minutes. Enter a new password of 8 to 128 characters and repeat it in Confirm new password.',
            'Select Reset password. After the success message, select Back to sign in and log in with your new password.',
        ],
        'notes' => ['Wait at least 60 seconds before using Resend code. Use the newest code; an expired code or too many failed attempts requires a new code.', 'Business password recovery is for active owner accounts. Staff should ask the business owner for help; this flow is separate from the customer shop account.', 'Enter passwords and reset codes only in MKPOS. Never send them to this bot or support chat. Other devices may require sign-in again after a reset.'],
    ],
    'platforms' => ['web' => 'Desktop web', 'mobile' => 'Mobile web', 'windows' => 'Windows 64-bit', 'windows32' => 'Windows 32-bit', 'android' => 'Android'],
    'downloads_note' => 'Web links open MKPOS in a browser without an installer. Native downloads are offered only when a release record and its file are available. No iOS or macOS installer is listed by this server.',
];

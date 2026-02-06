<?php
// careers/includes/brand.local.php (EXAMPLE)
//
// 1) Copy this file to: careers/includes/brand.local.php
// 2) Edit values for your care home
//
// brand.local.php overrides the default careers/includes/brand.php
// without you needing to modify the shipped file.
//
// Tip: you can use sc_asset_url('careers/logo.svg') for a local logo.

// Based on the default brand.php:

return [
    'product' => [
        'name'            => 'SmartCare Solutions',
        'short'           => 'SmartCare',
        'tagline'         => 'Complete care solutions',
        'show_powered_by' => true,
    ],

    'org' => [
        'name'        => 'Woodview Care Home',
        'short'       => 'Woodview',
        'portal_name' => 'Complete Care Portal', // nicer public name
        'location'    => 'United Kingdom',

        // Logo + favicon (image-first, text fallback)
        'logo' => [
            'url' => 'https://zapsite.co.uk/smartcare-ui/public/assets/logo.svg',
            'alt' => 'Woodview Care Home',
        ],
        'logo_text' => 'W',

        'favicon' => [
            'url' => 'https://zapsite.co.uk/smartcare-ui/public/assets/favicon.ico',
        ],

        'phone'   => '01234 567890',
        'email'   => 'recruitment@woodview.co.uk',
        'address' => 'Care Home Address, Town/City, Postcode',
    ],

    // Optional: fine to keep here as TOKENS.
    // Best practice: map these tokens to CSS variables in your CSS.
    'colors' => [
        'primary'      => '#2563EB',
        'primary_soft' => '#DBEAFE',
        'accent'       => '#10B981',
        'danger'       => '#E11D48',
        'warning'      => '#F59E0B',
        'bg'           => '#F8FAFC',
        'panel'        => '#FFFFFF',
        'text'         => '#0F172A',
        'muted'        => '#64748B',
        'border'       => '#E2E8F0',
    ],

    'careers' => [
        'jobs_title'    => 'Join our care home team',
        'jobs_subtitle' => 'Apply for one of the roles below and our team will be in touch.',
        'jobs_intro'    => 'We’re a single independent care home looking for people who are kind, reliable and committed to great care.',
        'response_sla'  => 'We aim to respond within 5 working days.',
        'notice'        => 'If you need the application form in a different format, please contact the home.',
        'no_agencies'   => 'No agencies please',
    ],

    'recruitment' => [
        'default_notice_period'        => '4 weeks',
        'default_earliest_start_days'  => 14,
        'stalled_days'                 => 7,
    ],

    'ui' => [
        'container_class'   => 'max-w-6xl',
        'show_help_link'    => true,
        'show_contact_bar'  => true,
        'show_footer_links' => true,
        // Optional admin sidebar width override:
        // 'admin_sidebar_width' => 'lg:w-64 xl:w-72',
    ],
];

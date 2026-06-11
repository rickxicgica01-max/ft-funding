<?php
/* =====================================================================
   SITE CONFIG  —  the ONLY file you edit when adding this blog to a site.
   Copy the whole folder into a site, change the values below, and you're done.
   ===================================================================== */
return [

    // The blog's title — shown in the header, page titles, and footer.
    'site_name' => 'FT Funding Insights',

    // Admin password, stored as a bcrypt HASH (never the plain password).
    // Generate one in a terminal with:
    //   php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
    'admin_hash' => '$2y$12$8MfZetxZxL6K/Jzn4LBpVOFhIjAe4zAb95gr42k5aGrzlUNdR.Mz.',

    // WHERE the blog lives on the site:
    //   ''       -> a web root or its own subdomain   (e.g. blog.example.com)
    //   '/blog'  -> a subfolder of an existing site   (e.g. example.com/blog)
    'base_url' => '/blog',

    // Optional link back to the main website, shown in the blog header.
    // Leave 'main_site_url' empty ('') to hide the link entirely.
    'main_site_url'   => '/resources',
    'main_site_label' => 'FT Funding',

    // How many posts to show per page (pagination).
    'posts_per_page' => 6,   // public blog homepage (cards are tall)
    'admin_per_page' => 12,  // admin dashboard (rows are compact)
];

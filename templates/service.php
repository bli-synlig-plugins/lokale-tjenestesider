<?php

if (!defined('ABSPATH')) {
    exit;
}

$context = LTS_Context::get();
get_header();
if (is_array($context)) {
    LTS_Templates::render($context, 'service');
}
get_footer();

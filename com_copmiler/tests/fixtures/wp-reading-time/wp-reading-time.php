<?php
/**
 * Plugin Name: Reading Time & Shortcode Badge
 * Description: Adds estimated reading time to articles and provides a [reading_badge] shortcode.
 * Version: 2.0.1
 * Author: Test Author
 * Text Domain: wp-reading-time
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') or die('No script kiddies please!');

function wprt_append_reading_time($content)
{
    $words = str_word_count(strip_tags((string) $content));
    $mins  = max(1, (int) ceil($words / 200));
    return '<p class="reading-time">' . esc_html__('Reading time:', 'wp-reading-time') . ' ' . $mins . ' min</p>' . $content;
}
add_filter('the_content', 'wprt_append_reading_time');

function wprt_shortcode_badge($atts = [])
{
    $atts = shortcode_atts(['label' => 'Quick Read'], $atts, 'reading_badge');
    return '<span class="badge-rt">' . esc_html($atts['label']) . '</span>';
}
add_shortcode('reading_badge', 'wprt_shortcode_badge');

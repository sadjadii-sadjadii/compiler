<?php
/**
 * Plugin Name: Weather Card Widget
 * Plugin URI: https://example.com/wp-weather-widget
 * Description: Displays a clean weather card widget in the sidebar.
 * Version: 1.2.0
 * Author: Test Author
 * Text Domain: wp-weather-widget
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wp_Weather_Card_Widget extends WP_Widget
{
    public function __construct()
    {
        parent::__construct(
            'wp_weather_card',
            __('Weather Card', 'wp-weather-widget'),
            ['description' => __('Shows city weather summary.', 'wp-weather-widget')]
        );
    }

    public function widget($args, $instance)
    {
        $title = !empty($instance['title']) ? $instance['title'] : 'Weather';
        $city  = !empty($instance['city']) ? $instance['city'] : 'Tehran';
        $unit  = !empty($instance['unit']) ? $instance['unit'] : 'C';

        echo $args['before_widget'];
        echo $args['before_title'] . esc_html($title) . $args['after_title'];
        echo '<div class="weather-box">' . esc_html($city) . ': 22°' . esc_html($unit) . '</div>';
        echo $args['after_widget'];
    }

    public function form($instance)
    {
        $title = $instance['title'] ?? 'Weather';
        $city  = $instance['city'] ?? 'Tehran';
        $unit  = $instance['unit'] ?? 'C';
        echo '<input name="' . esc_attr($this->get_field_name('title')) . '" value="' . esc_attr($title) . '">';
        echo '<input name="' . esc_attr($this->get_field_name('city')) . '" value="' . esc_attr($city) . '">';
        echo '<input name="' . esc_attr($this->get_field_name('unit')) . '" value="' . esc_attr($unit) . '">';
    }
}

add_action('widgets_init', function () {
    register_widget('Wp_Weather_Card_Widget');
});

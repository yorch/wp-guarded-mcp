<?php
/**
 * Smoke test Kirki fields. Registered as an mu-plugin so they exist on every
 * request, the way Kirki fields are registered in a theme's functions.php.
 * The smoke-kirki.sh suite expects these fields to be present.
 */
if ( !defined( 'ABSPATH' ) ) {
  exit;
}

add_action( 'init', function () {
  if ( !class_exists( 'Kirki' ) ) {
    return;
  }

  Kirki::add_config( 'smoke_config', [ 'option_type' => 'theme_mod' ] );
  Kirki::add_field( 'smoke_config', [
    'type'     => 'color',
    'settings' => 'smoke_color_mod',
    'label'    => 'Smoke Color',
    'section'  => 'colors',
    'default'  => '#ff0000',
  ] );

  Kirki::add_config( 'smoke_opt_config', [ 'option_type' => 'option', 'option_name' => 'smoke_options' ] );
  Kirki::add_field( 'smoke_opt_config', [
    'type'     => 'text',
    'settings' => 'smoke_text_opt',
    'label'    => 'Smoke Text',
    'section'  => 'title_tagline',
    'default'  => 'default-text',
  ] );

  Kirki::add_config( 'smoke_standalone_config', [ 'option_type' => 'option' ] );
  Kirki::add_field( 'smoke_standalone_config', [
    'type'     => 'text',
    'settings' => 'smoke_standalone_opt',
    'label'    => 'Smoke Standalone',
    'section'  => 'title_tagline',
    'default'  => 'standalone-default',
  ] );

  Kirki::add_field( 'smoke_config', [
    'type'     => 'text',
    'settings' => 'smoke_api_key',
    'label'    => 'Smoke API Key',
    'section'  => 'title_tagline',
    'default'  => 'sk-live-secret-12345',
  ] );

  // A standalone option field named "siteurl" to test the option guard.
  Kirki::add_config( 'smoke_guard', [ 'option_type' => 'option' ] );
  Kirki::add_field( 'smoke_guard', [
    'type'     => 'text',
    'settings' => 'siteurl',
    'label'    => 'Site URL',
    'section'  => 'title_tagline',
    'default'  => '',
  ] );
} );

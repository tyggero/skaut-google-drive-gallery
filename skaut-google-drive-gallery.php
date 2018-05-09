<?php declare(strict_types=1);
/*
Plugin Name:	Google drive gallery
Plugin URI:
Description:	A Wordpress gallery using Google drive as file storage
Version:		0.1
Author:			Marek Dědič
Author URI:
License:		MIT
License URI:

MIT License

Copyright (c) 2018 Marek Dědič

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/

defined('ABSPATH') or die('Die, die, die!');

include_once('bundled/vendor_includes.php');

if(!class_exists('Sgdg_plugin'))
{
	class Sgdg_plugin
	{
		public static function getRawGoogleClient() : Google_Client
		{
			$client = new Google_Client();
			$client->setAuthConfig(['client_id' => get_option('sgdg_client_id'), 'client_secret' => get_option('sgdg_client_secret'), 'redirect_uris' => [esc_url_raw(admin_url('options-general.php?page=sgdg&action=oauth_redirect'))]]);
			$client->setAccessType('offline');
			$client->setApprovalPrompt('force');
			$client->addScope(Google_Service_Drive::DRIVE_READONLY);
			return $client;
		}

		public static function getDriveClient() : Google_Service_Drive
		{
			$client = self::getRawGoogleClient();
			$accessToken = get_option('sgdg_access_token');
			$client->setAccessToken($accessToken);

			if($client->isAccessTokenExpired())
			{
				$client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
				$newAccessToken = $client->getAccessToken();
				$mergedAccessToken = array_merge($accessToken, $newAccessToken);
				update_option('sgdg_access_token', $mergedAccessToken);
			}

			return new Google_Service_Drive($client);
		}

		public static function init() : void
		{
			add_action('plugins_loaded', ['Sgdg_plugin', 'load_textdomain']);
			add_action('init', ['Sgdg_plugin', 'register_shortcodes']);
			add_action('wp_enqueue_scripts', ['Sgdg_plugin', 'register_scripts_styles']);
			add_action('admin_init', ['Sgdg_plugin', 'action_handler']);
			add_action('admin_init', ['Sgdg_plugin', 'register_settings']);
			add_action('admin_menu', ['Sgdg_plugin', 'options_page']);
			if(!get_option('sgdg_access_token'))
			{
				add_action('admin_init', ['Sgdg_plugin', 'settings_oauth_grant']);
			}
			else
			{
				add_action('admin_init', ['Sgdg_plugin', 'settings_oauth_revoke']);
				add_action('admin_init', ['Sgdg_plugin', 'settings_root_selection']);
				add_action('admin_init', ['Sgdg_plugin', 'settings_other_options']);
				add_action('admin_enqueue_scripts', ['Sgdg_plugin', 'enqueue_ajax']);
				add_action('wp_ajax_list_gdrive_dir', ['Sgdg_plugin', 'handle_ajax_list_gdrive_dir']);
			}
		}

		public static function load_textdomain() : void
		{
			load_plugin_textdomain('skaut-google-drive-gallery', false, basename( dirname( __FILE__ ) ) . '/languages/' );
		}

		public static function register_shortcodes() : void
		{
			add_shortcode('sgdg', ['Sgdg_plugin', 'shortcode_gallery']);
		}

		public static function register_scripts_styles() : void
		{
			wp_register_script('sgdg_masonry', plugins_url('/bundled/masonry.pkgd.min.js', __FILE__), ['jquery']);
			wp_register_script('sgdg_imagesloaded', plugins_url('/bundled/imagesloaded.pkgd.min.js', __FILE__), ['jquery']);
			wp_register_script('sgdg_imagelightbox_script', plugins_url('/bundled/imagelightbox.min.js', __FILE__), ['jquery']);
			wp_register_script('sgdg_gallery_init', plugins_url('/js/gallery_init.js', __FILE__), ['jquery']);
			wp_register_style('sgdg_imagelightbox_style', plugins_url('/bundled/imagelightbox.min.css', __FILE__));
			wp_register_style('sgdg_gallery_css', plugins_url('/css/gallery.css', __FILE__));
		}

		public static function shortcode_gallery(array $atts = []) : string
		{
			wp_enqueue_script('sgdg_masonry');
			wp_enqueue_script('sgdg_imagesloaded');
			wp_enqueue_script('sgdg_imagelightbox_script');
			wp_enqueue_script('sgdg_gallery_init');
			wp_localize_script('sgdg_gallery_init', 'sgdg_jquery_localize', [
				'thumbnail_size' => get_option('sgdg_thumbnail_size', 250)
			]);
			wp_enqueue_style('sgdg_imagelightbox_style');
			wp_enqueue_style('sgdg_gallery_css');
			wp_add_inline_style('sgdg_gallery_css', '.grid-item { width: ' . get_option('sgdg_thumbnail_size', 250) . 'px; }');
			if(isset($atts['name']))
			{
				$client = self::getDriveClient();
				$path = get_option('sgdg_root_dir', ['root']);
				$root = end($path);
				$pageToken = null;
				do
				{
					$optParams = [
						'q' => '"' . $root . '" in parents and trashed = false',
						'supportsTeamDrives' => true,
						'includeTeamDriveItems' => true,
						'pageToken' => $pageToken,
						'pageSize' => 1000,
						'fields' => 'nextPageToken, files(id, name)'
					];
					$response = $client->files->listFiles($optParams);
					foreach($response->getFiles() as $file)
					{
						if($file->getName() == $atts['name'])
						{
							return self::render_gallery($file->getId());
						}
					}
					$pageToken = $response->pageToken;
				}
				while($pageToken != null);
			}
			return __('No such gallery found.', 'skaut-google-drive-gallery');
		}

		private static function render_gallery($id) : string
		{
			$client = self::getDriveClient();
			$ret = '<div class="grid">';
			$pageToken = null;
			do
			{
				$optParams = [
					'q' => '"' . $id . '" in parents and mimeType contains "image/" and trashed = false',
					'supportsTeamDrives' => true,
					'includeTeamDriveItems' => true,
					'pageToken' => $pageToken,
					'pageSize' => 1000,
					'fields' => 'nextPageToken, files(thumbnailLink)'
				];
				$response = $client->files->listFiles($optParams);
				foreach($response->getFiles() as $file)
				{
					$ret .= '<div class="grid-item"><a data-imagelightbox="a" href="' . substr($file->getThumbnailLink(), 0, -3) . get_option('sgdg_preview_size', 1920) . '"><img src="' . substr($file->getThumbnailLink(), 0, -4) . 'w' . get_option('sgdg_thumbnail_size', 250) . '"></a></div>';
				}
				$pageToken = $response->pageToken;
			}
			while($pageToken != null);
			$ret .= '</div>';
			return $ret;
		}

		public static function action_handler() : void
		{
			if(isset($_GET['page']) && $_GET['page'] === 'sgdg' && isset($_GET['action']))
			{

				if($_GET['action'] === 'oauth_grant')
				{
					$client = self::getRawGoogleClient();
					$auth_url = $client->createAuthUrl();
					header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
				}
				elseif($_GET['action'] === 'oauth_redirect')
				{
					if(isset($_GET['code']) && !get_option('sgdg_access_token'))
					{
						$client = self::getRawGoogleClient();
						$client->authenticate($_GET['code']);
						$access_token = $client->getAccessToken();
						update_option('sgdg_access_token', $access_token);
					}
					header('Location: ' . esc_url_raw(admin_url('options-general.php?page=sgdg')));
				}
				elseif($_GET['action'] === 'oauth_revoke' && get_option('sgdg_access_token'))
				{
					$client = self::getRawGoogleClient();
					$client->revokeToken();
					delete_option('sgdg_access_token');
					header('Location: ' . esc_url_raw(admin_url('options-general.php?page=sgdg')));
				}
			}
		}

		public static function register_settings() : void
		{
			register_setting('sgdg', 'sgdg_client_id', ['type' => 'string']);
			register_setting('sgdg', 'sgdg_client_secret', ['type' => 'string']);
			register_setting('sgdg', 'sgdg_root_dir', ['type' => 'string', 'sanitize_callback' => ['Sgdg_plugin', 'decode_root_dir']]);
			register_setting('sgdg', 'sgdg_thumbnail_size', ['type' => 'integer', 'sanitize_callback' => ['Sgdg_plugin', 'sanitize_thumbnail_size']]);
			register_setting('sgdg', 'sgdg_preview_size', ['type' => 'integer', 'sanitize_callback' => ['Sgdg_plugin', 'sanitize_preview_size']]);
		}

		public static function settings_oauth_grant() : void
		{
			add_settings_section('sgdg_auth', __('Step 1: Authentication', 'skaut-google-drive-gallery'), ['Sgdg_plugin', 'auth_html'], 'sgdg');
			add_settings_field('sgdg_redirect_uri', __('Authorized redirect URL', 'skaut-google-drive-gallery'), ['Sgdg_plugin', 'redirect_uri_html'], 'sgdg', 'sgdg_auth');
			add_settings_field('sgdg_client_id', __('Client ID', 'skaut-google-drive-gallery'), ['Sgdg_plugin', 'client_id_html'], 'sgdg', 'sgdg_auth');
			add_settings_field('sgdg_client_secret', __('Client Secret', 'skaut-google-drive-gallery'), ['Sgdg_plugin', 'client_secret_html'], 'sgdg', 'sgdg_auth');
		}

		public static function settings_oauth_revoke() : void
		{
			add_settings_section('sgdg_auth', __('Step 1: Authentication', 'skaut-google-drive-gallery'), ['Sgdg_plugin', 'revoke_html'], 'sgdg');
			add_settings_field('sgdg_redirect_uri', __('Authorized redirect URL', 'skaut-google-drive-gallery'), ['Sgdg_plugin', 'redirect_uri_html'], 'sgdg', 'sgdg_auth');
			add_settings_field('sgdg_client_id', __('Client ID', 'skaut-google-drive-gallery'), ['Sgdg_plugin', 'client_id_html_readonly'], 'sgdg', 'sgdg_auth');
			add_settings_field('sgdg_client_secret', __('Client Secret', 'skaut-google-drive-gallery'), ['Sgdg_plugin', 'client_secret_html_readonly'], 'sgdg', 'sgdg_auth');
		}

		public static function settings_root_selection() : void
		{
			add_settings_section('sgdg_dir_select', __('Step 2: Root directory selection', 'skaut-google-drive-gallery'), ['Sgdg_plugin', 'dir_select_html'], 'sgdg');
		}

		public static function settings_other_options() : void
		{
			add_settings_section('sgdg_options', __('Step 3: Other options', 'skaut-google-drive-gallery'), ['Sgdg_plugin', 'other_options_html'], 'sgdg');
			add_settings_field('sgdg_thumbnail_size', __('Thumbnail size', 'skaut-google-drive-gallery'), ['Sgdg_plugin', 'thumbnail_size_html'], 'sgdg', 'sgdg_options');
			add_settings_field('sgdg_preview_size', __('Preview size', 'skaut-google-drive-gallery'), ['Sgdg_plugin', 'preview_size_html'], 'sgdg', 'sgdg_options');
		}

		public static function enqueue_ajax($hook) : void
		{
			if($hook === 'settings_page_sgdg')
			{
				wp_enqueue_script('sgdg_root_selector_ajax', plugins_url('/js/root_selector.js', __FILE__), ['jquery']);
				wp_localize_script('sgdg_root_selector_ajax', 'sgdg_jquery_localize', [
					'ajax_url' => admin_url('admin-ajax.php'),
					'nonce' => wp_create_nonce('sgdg_root_selector'),
					'root_dir' => get_option('sgdg_root_dir', [])
				]);
			}
		}
		public static function handle_ajax_list_gdrive_dir() : void
		{
			check_ajax_referer('sgdg_root_selector');

			$client = self::getDriveClient();
			$path = isset($_GET['path']) ? $_GET['path'] : [];
			$ret = ['path' => [], 'contents' => []];

			if(count($path) > 0)
			{
				if($path[0] === 'root')
				{
					$ret['path'][] = __('My Drive', 'skaut-google-drive-gallery');
				}
				else
				{
					$response = $client->teamdrives->get($path[0], ['fields' => 'name']);
					$ret['path'][] = $response->getName();
				}
			}
			foreach(array_slice($path, 1) as $pathElement)
			{
				$response = $client->files->get($pathElement, ['supportsTeamDrives' => true, 'fields' => 'name']);
				$ret['path'][] = $response->getName();
			}

			if(count($path) === 0)
			{
				$ret['contents'][] = ['name' => __('My Drive', 'skaut-google-drive-gallery'), 'id' => 'root'];
				$pageToken = null;
				do
				{
					$optParams = [
						'pageToken' => $pageToken,
						'pageSize' => 100,
						'fields' => 'nextPageToken, teamDrives(id, name)'
					];
					$response = $client->teamdrives->listTeamdrives($optParams);
					foreach($response->getTeamdrives() as $teamdrive)
					{
						$ret['contents'][] = ['name' => $teamdrive->getName(), 'id' => $teamdrive->getId()];
					}
					$pageToken = $response->pageToken;
				}
				while($pageToken != null);

				wp_send_json($ret);
			}

			$root = end($path);

			$pageToken = null;
			do
			{
				$optParams = [
					'q' => '"' . $root . '" in parents and mimeType = "application/vnd.google-apps.folder" and trashed = false',
					'supportsTeamDrives' => true,
					'includeTeamDriveItems' => true,
					'pageToken' => $pageToken,
					'pageSize' => 1000,
					'fields' => 'nextPageToken, files(id, name)'
				];
				$response = $client->files->listFiles($optParams);
				foreach($response->getFiles() as $file)
				{
					$ret['contents'][] = ['name' => $file->getName(), 'id' => $file->getId()];
				}
				$pageToken = $response->pageToken;
			}
			while($pageToken != null);

			wp_send_json($ret);
		}

		public static function options_page() : void
		{
			add_options_page(__('Google drive gallery', 'skaut-google-drive-gallery'), __('Google drive gallery', 'skaut-google-drive-gallery'), 'manage_options', 'sgdg', ['Sgdg_plugin', 'options_page_html']);
		}

		public static function options_page_html() : void
		{
			if (!current_user_can('manage_options'))
			{
				return;
			}

			settings_errors('sgdg_messages');
			echo('<div class="wrap">');
			echo('<h1>' . esc_html(get_admin_page_title()) . '</h1>');
			echo('<form action="options.php" method="post">');
			settings_fields('sgdg');
			do_settings_sections('sgdg');
			submit_button(__('Save Settings', 'skaut-google-drive-gallery'));
			echo('</form>');
			echo('</div>');
		}

		public static function auth_html() : void
		{
			echo('<p>' . __('Create a Google app and provide the following details:', 'skaut-google-drive-gallery') . '</p>');
			echo('<a class="button button-primary" href="' . esc_url_raw(admin_url('options-general.php?page=sgdg&action=oauth_grant')) . '">' . __('Grant Permission', 'skaut-google-drive-gallery') . '</a>');
		}

		public static function revoke_html() : void
		{
			echo('<a class="button button-primary" href="' . esc_url_raw(admin_url('options-general.php?page=sgdg&action=oauth_revoke')) . '">' . __('Revoke Permission', 'skaut-google-drive-gallery') . '</a>');
		}

		public static function dir_select_html() : void
		{
			echo('<input id="sgdg_root_dir" type="hidden" name="sgdg_root_dir" value="' . htmlentities(json_encode(get_option('sgdg_root_dir', []), JSON_UNESCAPED_UNICODE)) . '">');
			echo('<table class="widefat">');
			echo('<thead>');
			echo('<tr>');
			echo('<th class="sgdg_root_selector_path"></th>');
			echo('</tr>');
			echo('</thead>');
			echo('<tbody id="sgdg_root_selector_body"></tbody>');
			echo('<tfoot>');
			echo('<tr>');
			echo('<td class="sgdg_root_selector_path"></td>');
			echo('</tr>');
			echo('</tfoot>');
			echo('</table>');
		}

		public static function other_options_html() : void
		{}

		public static function client_id_html() : void
		{
			self::field_html('sgdg_client_id');
		}

		public static function client_secret_html() : void
		{
			self::field_html('sgdg_client_secret');
		}

		public static function client_id_html_readonly() : void
		{
			self::field_html('sgdg_client_id', true);
		}

		public static function client_secret_html_readonly() : void
		{
			self::field_html('sgdg_client_secret', true);
		}

		private static function field_html(string $setting_name, bool $readonly = false) : void
		{
			$setting = get_option($setting_name);
			echo('<input type="text" name="' . $setting_name . '" value="' . (isset($setting) ? esc_attr($setting) : '') . '" ' . ($readonly ? 'readonly ' : '') . 'class="regular-text code">');
		}

		public static function redirect_uri_html() : void
		{
			echo('<input type="text" value="' . esc_url_raw(admin_url('options-general.php?page=sgdg&action=oauth_redirect')) . '" readonly class="regular-text code">');
		}

		public static function thumbnail_size_html() : void
		{
			self::size_html('sgdg_thumbnail_size', 250);
		}

		public static function preview_size_html() : void
		{
			self::size_html('sgdg_preview_size', 1920);
		}

		private static function size_html(string $setting_name, int $default) : void
		{
			$setting = get_option($setting_name, $default);
			echo('<input type="text" name="' . $setting_name . '" value="' . esc_attr($setting) . '" class="regular-text">');
		}

		public static function decode_root_dir($path) : array
		{
			if(!is_array($path))
			{
				$path =  json_decode($path, true);
			}
			if(count($path) === 0)
			{
				$path = ['root'];
			}
			return $path;
		}

		public static function sanitize_thumbnail_size($size) : int
		{
			return self::sanitize_size($size, 250);
		}

		public static function sanitize_preview_size($size) : int
		{
			return self::sanitize_size($size, 1920);
		}

		private static function sanitize_size($size, int $default) : int
		{
			if(!is_int($size))
			{
				$size = intval($size);
			}
			if($size === 0)
			{
				$size = 250;
			}
			return $size;
		}
	}

	Sgdg_plugin::init();
}

<?php declare(strict_types=1);
namespace Sgdg\Admin\OptionsPage\RootSelection;

if(!is_admin())
{
	return;
}

function register() : void
{
	add_action('admin_init', '\\Sgdg\\Admin\\OptionsPage\\RootSelection\\add');
	add_action('admin_enqueue_scripts', '\\Sgdg\\Admin\\OptionsPage\\RootSelection\\enqueue_ajax');
	add_action('wp_ajax_list_gdrive_dir', '\\Sgdg\\Admin\\OptionsPage\\RootSelection\\handle_ajax');
}

function add() : void
{
	add_settings_section('sgdg_root_selection', esc_html__('Step 2: Root directory selection', 'skaut-google-drive-gallery'), '\\Sgdg\\Admin\\OptionsPage\\RootSelection\\html', 'sgdg');
	\Sgdg_plugin::$rootPath->register();
}

function html() : void
{
	\Sgdg_plugin::$rootPath->html();
	echo('<table class="widefat">');
	echo('<thead>');
	echo('<tr>');
	echo('<th class="sgdg_root_selection_path"></th>');
	echo('</tr>');
	echo('</thead>');
	echo('<tbody id="sgdg_root_selection_body"></tbody>');
	echo('<tfoot>');
	echo('<tr>');
	echo('<td class="sgdg_root_selection_path"></td>');
	echo('</tr>');
	echo('</tfoot>');
	echo('</table>');
}

function enqueue_ajax($hook) : void
{
	if($hook === 'settings_page_sgdg')
	{
		wp_enqueue_script('sgdg_root_selection_ajax', plugins_url('/js/root_selection.js', dirname(__FILE__)), ['jquery']);
		wp_localize_script('sgdg_root_selection_ajax', 'sgdg_jquery_localize', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('sgdg_root_selection'),
			'root_dir' => \Sgdg_plugin::$rootPath->get([])
		]);
	}
}

function handle_ajax() : void
{
	check_ajax_referer('sgdg_root_selection');
	$client = \Sgdg\Frontend\GoogleAPILib\getDriveClient();

	$path = isset($_GET['path']) ? $_GET['path'] : [];
	$ret = ['path' => pathIDsToNames($client, $path), 'contents' => []];

	if(count($path) === 0)
	{
		$ret['contents'] = listTeamdrives($client);
	}
	else
	{
		$ret['contents'] = listFiles($client, end($path));
	}
	wp_send_json($ret);
}

function pathIDsToNames($client, $path) : array
{
	$ret = [];
	if(count($path) > 0)
	{
		if($path[0] === 'root')
		{
			$ret[] = esc_html__('My Drive', 'skaut-google-drive-gallery');
		}
		else
		{
			$response = $client->teamdrives->get($path[0], ['fields' => 'name']);
			$ret[] = $response->getName();
		}
	}
	foreach(array_slice($path, 1) as $pathElement)
	{
		$response = $client->files->get($pathElement, ['supportsTeamDrives' => true, 'fields' => 'name']);
		$ret[] = $response->getName();
	}
	return $ret;
}

function listTeamdrives($client) : array
{
	$ret = [['name' => esc_html__('My Drive', 'skaut-google-drive-gallery'), 'id' => 'root']];
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
			$ret[] = ['name' => $teamdrive->getName(), 'id' => $teamdrive->getId()];
		}
		$pageToken = $response->pageToken;
	}
	while($pageToken != null);
	return $ret;
}

function listFiles($client, $root) : array
{
	$ret = [];
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
			$ret[] = ['name' => $file->getName(), 'id' => $file->getId()];
		}
		$pageToken = $response->pageToken;
	}
	while($pageToken != null);
	return $ret;
}

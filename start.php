<?php
/**
 *	Elgg videos plugin
 *	Author : Sarath C | Team Webgalli
 *	Team Webgalli | Elgg developers and consultants
 *	Mail : webgalli [at] gmail [dot] com
 *	Web	: http://webgalli.com 
 *	Skype : 'team.webgalli'
 *	@package Elgg-videos
 * 	Plugin info : Upload/ Embed videos. Save uploaded videos in youtube and save your bandwidth and server space
 *	Licence : GNU2
 *	Copyright : Team Webgalli 2011-2015
 */

elgg_register_event_handler('init', 'system', 'videos_init');

/**
 * video init
 */
function videos_init() {
	$root = dirname(__FILE__);
	elgg_register_library('elgg:videos', elgg_get_plugins_path() . 'videos/lib/videos.php');
	// For now we can use the embed video library of Cash. Once that plugin is updated to 1.8
	// we can use the elgg's manifest file to make the users use that library along with this
	// and remove the library from this plugin
	elgg_register_library('elgg:videos:embed', elgg_get_plugins_path() . 'videos/lib/embed_video.php');
	// actions
	$action_path = "$root/actions/videos";
	elgg_register_action('videos/save', "$action_path/save.php");
	elgg_register_action('videos/delete', "$action_path/delete.php");
	// menus
	elgg_register_menu_item('site', array(
		'name' => 'videos',
		'text' => elgg_echo('videos'),
		'href' => 'videos/all'
	));
	elgg_register_plugin_hook_handler('register', 'menu:owner_block', 'videos_owner_block_menu');
	elgg_register_page_handler('videos', 'videos_page_handler');
	elgg_register_widget_type('videos', elgg_echo('videos'), elgg_echo('videos:widget:description'));
	// Register granular notification for this type
	register_notification_object('object', 'videos', elgg_echo('videos:new'));
	// Listen to notification events and supply a more useful message
	elgg_register_plugin_hook_handler('notify:entity:message', 'object', 'videos_notify_message');
	// Register a URL handler for videos
	elgg_register_entity_url_handler('object', 'videos', 'video_url');
	// Register entity type for search
	elgg_register_entity_type('object', 'videos');
	// Groups
	add_group_tool_option('videos', elgg_echo('videos:enablevideos'), true);
	elgg_extend_view('groups/tool_latest', 'videos/group_module');
	// Process the views
	$views = array('output/longtext','output/plaintext');
	foreach($views as $view){
		elgg_register_plugin_hook_handler("view", $view, "videos_view_filter", 500);
	}	
}
/**
 * Process the Elgg views for a matching video URL
*/
function videos_view_filter($hook, $entity_type, $returnvalue, $params){
	elgg_load_library('elgg:videos:embed');
	$patterns = array(	'#(((http://)?)|(^./))(((www.)?)|(^./))youtube\.com/watch[?]v=([^\[\]()<.,\s\n\t\r]+)#i',
						'#(((http://)?)|(^./))(((www.)?)|(^./))youtu\.be/([^\[\]()<.,\s\n\t\r]+)#i',
						'/(http:\/\/)(www\.)?(vimeo\.com\/groups)(.*)(\/videos\/)([0-9]*)/',
						'/(http:\/\/)(www\.)?(vimeo.com\/)([0-9]*)/',
						'/(http:\/\/)(www\.)?(metacafe\.com\/watch\/)([0-9a-zA-Z_-]*)(\/[0-9a-zA-Z_-]*)(\/)/',
						'/(http:\/\/www\.dailymotion\.com\/.*\/)([0-9a-z]*)/',
						);
	$regexp = "<a\s[^>]*href=(\"??)([^\" >]*?)\\1[^>]*>(.*)<\/a>";
	$regexp = "<a\s[^>]*href=(\"??)([^\" >]*?)\\1[^>]*>(.*)<\/a>";
	if(preg_match_all("/$regexp/siU", $returnvalue, $matches, PREG_SET_ORDER)){
    // $matches[2] = array of link addresses
    // $matches[3] = array of link text - including HTML code	
		foreach($matches as $match){
			foreach ($patterns as $pattern){
				if (preg_match($pattern, $match[2]) > 0){
					$returnvalue = str_replace($match[0], videoembed_create_embed_object($match[2], uniqid('videos_embed_'), 350), $returnvalue);
				}				
			}
		}
	}
	return $returnvalue;
}	
 
/**
 * Dispatcher for videos.
 * URLs take the form of
 *  All videos:        videos/all
 *  User's videos:     videos/owner/<username>
 *  Friends' videos:   videos/friends/<username>
 *  View video:        videos/view/<guid>/<title>
 *  New video:         videos/add/<guid> (container: user, group, parent)
 *  Edit video:        videos/edit/<guid>
 *  Group videos:      videos/group/<guid>/owner
 * Title is ignored
 * @param array $page
 */
function videos_page_handler($page) {
	elgg_load_library('elgg:videos');
	elgg_push_breadcrumb(elgg_echo('videos'), 'videos/all');
	elgg_push_context('videos');
	// old group usernames
	if (substr_count($page[0], 'group:')) {
		preg_match('/group\:([0-9]+)/i', $page[0], $matches);
		$guid = $matches[1];
		if ($entity = get_entity($guid)) {
			videos_url_forwarder($page);
		}
	}
	// user usernames
	$user = get_user_by_username($page[0]);
	if ($user) {
		videos_url_forwarder($page);
	}
	$pages = dirname(__FILE__) . '/pages/videos';
	switch ($page[0]) {
		case "all":
			include "$pages/all.php";
			break;
		case "owner":
			include "$pages/owner.php";
			break;
		case "friends":
			include "$pages/friends.php";
			break;
		case "read":
		case "view":
			set_input('guid', $page[1]);
			include "$pages/view.php";
			break;
		case "add":
			gatekeeper();
			include "$pages/add.php";
			break;
		case "edit":
			gatekeeper();
			set_input('guid', $page[1]);
			include "$pages/edit.php";
			break;
		case 'group':
			group_gatekeeper();
			include "$pages/owner.php";
			break;
		default:
			return false;
	}
	elgg_pop_context();
	return true;
}
/**
 * Forward to the new style of URLs
 *
 * @param string $page
 */
function videos_url_forwarder($page) {
	global $CONFIG;
	if (!isset($page[1])) {
		$page[1] = 'items';
	}
	switch ($page[1]) {
		case "read":
			$url = "{$CONFIG->wwwroot}videos/view/{$page[2]}/{$page[3]}";
			break;
		case "inbox":
			$url = "{$CONFIG->wwwroot}videos/inbox/{$page[0]}";
			break;
		case "friends":
			$url = "{$CONFIG->wwwroot}videos/friends/{$page[0]}";
			break;
		case "add":
			$url = "{$CONFIG->wwwroot}videos/add/{$page[0]}";
			break;
		case "items":
			$url = "{$CONFIG->wwwroot}videos/owner/{$page[0]}";
			break;
	}
	register_error(elgg_echo("changebookmark"));
	forward($url);
}
/**
 * Populates the ->getUrl() method for videoed objects
 * @param ElggEntity $entity The videoed object
 * @return string videoed item URL
 */
function video_url($entity) {
	global $CONFIG;
	$title = $entity->title;
	$title = elgg_get_friendly_title($title);
	return $CONFIG->url . "videos/view/" . $entity->getGUID() . "/" . $title;
}
/**
 * Add a menu item to an ownerblock
 * 
 * @param string $hook
 * @param string $type
 * @param array  $return
 * @param array  $params
 */
function videos_owner_block_menu($hook, $type, $return, $params) {
	if (elgg_instanceof($params['entity'], 'user')) {
		$url = "videos/owner/{$params['entity']->username}";
		$item = new ElggMenuItem('videos', elgg_echo('videos'), $url);
		$return[] = $item;
	} else {
		if ($params['entity']->videos_enable != 'no') {
			$url = "videos/group/{$params['entity']->guid}/owner";
			$item = new ElggMenuItem('videos', elgg_echo('videos:group'), $url);
			$return[] = $item;
		}
	}
	return $return;
}

/**
 * Returns the body of a notification message
 *
 * @param string $hook
 * @param string $entity_type
 * @param string $returnvalue
 * @param array  $params
 */
function videos_notify_message($hook, $entity_type, $returnvalue, $params) {
	$entity = $params['entity'];
	$to_entity = $params['to_entity'];
	$method = $params['method'];
	if (($entity instanceof ElggEntity) && ($entity->getSubtype() == 'videos')) {
		$descr = $entity->description;
		$title = $entity->title;
		global $CONFIG;
		$url = elgg_get_site_url() . "view/" . $entity->guid;
		if ($method == 'sms') {
			$owner = $entity->getOwnerEntity();
			return $owner->name . ' ' . elgg_echo("videos:via") . ': ' . $url . ' (' . $title . ')';
		}
		if ($method == 'email') {
			$owner = $entity->getOwnerEntity();
			return $owner->name . ' ' . elgg_echo("videos:via") . ': ' . $title . "\n\n" . $descr . "\n\n" . $entity->getURL();
		}
		if ($method == 'web') {
			$owner = $entity->getOwnerEntity();
			return $owner->name . ' ' . elgg_echo("videos:via") . ': ' . $title . "\n\n" . $descr . "\n\n" . $entity->getURL();
		}
	}
	return null;
}